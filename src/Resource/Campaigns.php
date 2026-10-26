<?php

namespace Skrybe\Resource;

use Skrybe\Exception\ApiException;
use Skrybe\Internal\Errors;
use Skrybe\Internal\Parse;
use Skrybe\Internal\Transport;

/**
 * Campaign rows are returned as the API sends them (snake_case keys): id,
 * title, subject, status (draft, scheduled, preparing, sending, sent, paused),
 * from_name, from_email, reply_to, recipients, to_send, sent_at and
 * scheduled_at (Unix seconds or null), timezone and source (`ui`, or `api`
 * for campaigns created by emails->send()).
 */
final class Campaigns
{
    public const STATUSES = ['draft', 'scheduled', 'preparing', 'sending', 'sent', 'paused'];
    public const ACTIVITY_TYPES = ['opens', 'clicks', 'bounces', 'complaints', 'unsubscribes'];
    public const MAX_PAGE = 100;
    public const MAX_ACTIVITY_PAGE = 1000;
    /** test-send.php refuses more than this many addresses per request. */
    public const MAX_TEST_EMAILS = 5;

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * `api/campaigns/create.php` creates a draft, and optionally sends or
     * schedules it in the same call. To send a draft that already exists, see
     * send().
     *
     * @param array{
     *     fromName: string,
     *     fromEmail: string,
     *     replyTo: string,
     *     title: string,
     *     subject: string,
     *     htmlText: string,
     *     plainText?: string,
     *     listIds?: list<string>,
     *     segmentIds?: list<int|string>,
     *     excludeListIds?: list<string>,
     *     excludeSegmentIds?: list<int|string>,
     *     queryString?: string,
     *     trackOpens?: int,
     *     trackClicks?: int,
     *     send?: bool,
     *     scheduleDateTime?: string,
     *     scheduleTimezone?: string,
     * } $params
     *     - listIds, segmentIds: needed only to send or schedule.
     *     - send: send immediately once created.
     *     - scheduleDateTime: schedule instead, e.g. "June 15, 2027 6:05pm".
     *       Minutes must be a multiple of 5.
     * @return array{outcome: 'created'|'sending'|'scheduled', campaignId?: string}
     *     campaignId only for a plain create: the sending and scheduling paths
     *     of create.php answer with bare prose and no id.
     */
    public function create(array $params): array
    {
        Errors::requireParams($params, ['fromName', 'fromEmail', 'replyTo', 'title', 'subject', 'htmlText']);
        $text = $this->transport->text('api/campaigns/create.php', [
            'from_name' => $params['fromName'],
            'from_email' => $params['fromEmail'],
            'reply_to' => $params['replyTo'],
            'title' => $params['title'],
            'subject' => $params['subject'],
            'html_text' => $params['htmlText'],
            'plain_text' => $params['plainText'] ?? null,
            'list_ids' => Shared::joinIds($params['listIds'] ?? null),
            'segment_ids' => Shared::joinIds($params['segmentIds'] ?? null),
            'exclude_list_ids' => Shared::joinIds($params['excludeListIds'] ?? null),
            // Sic: create.php reads exclude_segments_ids.
            'exclude_segments_ids' => Shared::joinIds($params['excludeSegmentIds'] ?? null),
            'query_string' => $params['queryString'] ?? null,
            'track_opens' => $params['trackOpens'] ?? null,
            'track_clicks' => $params['trackClicks'] ?? null,
            'send_campaign' => Shared::flag($params['send'] ?? false),
            'schedule_date_time' => $params['scheduleDateTime'] ?? null,
            'schedule_timezone' => $params['scheduleTimezone'] ?? null,
            // Asks for {"status": ..., "campaign_id": ...} instead of bare prose.
            'json' => 1,
        ]);
        $trimmed = trim($text);

        if ($trimmed === 'Campaign scheduled') {
            return ['outcome' => 'scheduled'];
        }
        if ($trimmed === 'Campaign created and now sending') {
            return ['outcome' => 'sending'];
        }
        if ($trimmed === 'Campaign created') {
            return ['outcome' => 'created'];
        }

        // The json=1 shape, built by hand server-side, so parsed leniently.
        $parsed = json_decode($trimmed, true);
        if (is_array($parsed) && is_string($parsed['status'] ?? null) && str_starts_with($parsed['status'], 'Campaign created')) {
            $result = ['outcome' => 'created'];
            if (isset($parsed['campaign_id'])) {
                $result['campaignId'] = (string) $parsed['campaign_id'];
            }
            return $result;
        }

        throw new ApiException('unexpected_response', 'Unexpected response from campaigns/create: ' . Errors::excerpt($trimmed), null, null, $text);
    }

    /**
     * One page, newest first. An empty page is an empty array, not an error.
     *
     * @param int|null $page 1-based
     * @param int|null $limit at most 100
     * @param string|null $status one of STATUSES
     * @return list<array<string, mixed>>
     */
    public function list(?int $page = null, ?int $limit = null, ?string $status = null): array
    {
        $body = $this->transport->text('api/campaigns/get-campaigns.php', [
            'page' => $page,
            'limit' => $limit,
            'status' => $status,
        ], retryable: true);
        if (Errors::isProseEmpty($body)) {
            return [];
        }
        return Parse::numberedRecords($body, 'campaign');
    }

    /**
     * Every page, fetched in order at the largest page size.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(?string $status = null): array
    {
        $all = [];
        for ($page = 1; ; $page++) {
            $rows = $this->list($page, self::MAX_PAGE, $status);
            array_push($all, ...$rows);
            if (count($rows) < self::MAX_PAGE) {
                return $all;
            }
        }
    }

    /**
     * A campaign row plus preheader, query_string, track_opens, track_clicks,
     * list_ids, exclude_list_ids, segment_ids, exclude_segment_ids and
     * web_version; html_text and plain_text only with $includeContent.
     *
     * @return array<string, mixed>
     */
    public function get(int $campaignId, bool $includeContent = false): array
    {
        $body = $this->transport->text('api/campaigns/get-campaign.php', [
            'campaign_id' => $campaignId,
            'include_content' => $includeContent ? 'yes' : null,
        ], retryable: true);
        return Parse::jsonObject($body, 'campaign');
    }

    /**
     * id, status, recipients, opens {total, unique, rate}, clicks {total,
     * unique, rate}, bounces {hard, soft}, complaints, unsubscribes and links
     * [{url, clicks, unique_clicks}]. The open rate is a percentage of
     * delivered; the click rate a percentage of recipients.
     *
     * @return array<string, mixed>
     */
    public function stats(int $campaignId): array
    {
        $body = $this->transport->text('api/campaigns/stats.php', ['campaign_id' => $campaignId], retryable: true);
        return Parse::jsonObject($body, 'campaign stats');
    }

    /**
     * `api/campaigns/send.php` sends an existing draft. It only ever claims a
     * draft, so a repeat after a lost response answers "Campaign has already
     * been sent" rather than sending twice. It is still not retried: that
     * answer would turn a success into a reported failure.
     *
     * @param array{
     *     listIds?: list<string>,
     *     segmentIds?: list<int|string>,
     *     excludeListIds?: list<string>,
     *     excludeSegmentIds?: list<int|string>,
     *     dryRun?: bool,
     * } $recipients at least one list or segment. dryRun runs every check and
     *     counts the recipients, but sends nothing.
     * @return array{status: 'sending'|'dry_run', campaign_id: int, recipients: int}
     */
    public function send(int $campaignId, array $recipients): array
    {
        $body = $this->transport->text('api/campaigns/send.php', ['campaign_id' => $campaignId] + self::recipientFields($recipients));
        return Parse::jsonObject($body, 'send result');
    }

    /**
     * Sends the campaign as a test to up to 5 addresses. Each request counts
     * against a per-brand limit shared with the dashboard (20 an hour), so it
     * is never retried.
     *
     * @param list<string> $emails
     * @return array{campaign_id: int, results: list<array{email: string, ok: bool, error: ?string}>}
     */
    public function testSend(int $campaignId, array $emails): array
    {
        $body = $this->transport->text('api/campaigns/test-send.php', [
            'campaign_id' => $campaignId,
            'emails' => implode(',', $emails),
        ]);
        return Parse::jsonObject($body, 'test send result');
    }

    /**
     * Schedules a draft, or moves a scheduled campaign. The recipients are
     * reserved against the quota now and handed back by unschedule().
     *
     * @param array{
     *     at: string,
     *     timezone?: string,
     *     listIds?: list<string>,
     *     segmentIds?: list<int|string>,
     *     excludeListIds?: list<string>,
     *     excludeSegmentIds?: list<int|string>,
     *     dryRun?: bool,
     * } $params
     *     - at: anything PHP's strtotime() reads, e.g. "2027-06-15 18:05".
     *     - timezone: e.g. "Africa/Lagos". Defaults to the account's timezone.
     * @return array{status: 'scheduled'|'dry_run', campaign_id: int, recipients: int, scheduled_at: int, timezone: string}
     */
    public function schedule(int $campaignId, array $params): array
    {
        Errors::requireParams($params, ['at']);
        $body = $this->transport->text('api/campaigns/schedule.php', ['campaign_id' => $campaignId] + self::recipientFields($params) + [
            'schedule_date_time' => $params['at'],
            'schedule_timezone' => $params['timezone'] ?? null,
        ]);
        return Parse::jsonObject($body, 'schedule result');
    }

    /**
     * Back to a draft, with its quota reservation refunded.
     *
     * @return array{status: 'draft', campaign_id: int}
     */
    public function unschedule(int $campaignId): array
    {
        $body = $this->transport->text('api/campaigns/unschedule.php', ['campaign_id' => $campaignId]);
        return Parse::jsonObject($body, 'unschedule result');
    }

    /**
     * Edits a draft or scheduled campaign and returns it as get() would. Only
     * the fields given change.
     *
     * @param array{
     *     title?: string,
     *     subject?: string,
     *     preheader?: string,
     *     fromName?: string,
     *     fromEmail?: string,
     *     replyTo?: string,
     *     htmlText?: string,
     *     plainText?: string,
     *     queryString?: string,
     *     trackOpens?: int,
     *     trackClicks?: int,
     * } $changes
     * @return array<string, mixed>
     */
    public function update(int $campaignId, array $changes): array
    {
        $body = $this->transport->text('api/campaigns/update-campaign.php', [
            'campaign_id' => $campaignId,
            'title' => $changes['title'] ?? null,
            'subject' => $changes['subject'] ?? null,
            'preheader' => $changes['preheader'] ?? null,
            'from_name' => $changes['fromName'] ?? null,
            'from_email' => $changes['fromEmail'] ?? null,
            'reply_to' => $changes['replyTo'] ?? null,
            'html_text' => $changes['htmlText'] ?? null,
            'plain_text' => $changes['plainText'] ?? null,
            'query_string' => $changes['queryString'] ?? null,
            'track_opens' => $changes['trackOpens'] ?? null,
            'track_clicks' => $changes['trackClicks'] ?? null,
        ]);
        return Parse::jsonObject($body, 'campaign');
    }

    /**
     * A new draft copied from the campaign, in the same brand.
     *
     * @return array<string, mixed>
     */
    public function duplicate(int $campaignId, ?string $title = null): array
    {
        $body = $this->transport->text('api/campaigns/duplicate-campaign.php', [
            'campaign_id' => $campaignId,
            'title' => $title,
        ]);
        return Parse::jsonObject($body, 'campaign');
    }

    /**
     * Deletes the campaign with its links and attachments; a sent one loses
     * its report. Refused while the campaign is preparing or sending.
     *
     * @return array{campaign_id: int, deleted: true}
     */
    public function delete(int $campaignId): array
    {
        $body = $this->transport->text('api/campaigns/delete-campaign.php', ['campaign_id' => $campaignId]);
        return Parse::jsonObject($body, 'delete result');
    }

    /**
     * A page of who opened, clicked, bounced, complained or unsubscribed.
     * Opens and clicks are in the order subscribers first did them.
     *
     * Each activity row has email, name and list_id; opens also opens and
     * country, clicks also clicks and links.
     *
     * @param string|null $type one of ACTIVITY_TYPES
     * @param int|null $limit at most 1000
     * @return array{campaign_id: int, type: string, page: int, limit: int, total: int, activity: list<array<string, mixed>>}
     */
    public function activity(int $campaignId, ?string $type = null, ?int $page = null, ?int $limit = null): array
    {
        $body = $this->transport->text('api/campaigns/get-activity.php', [
            'campaign_id' => $campaignId,
            'type' => $type,
            'page' => $page,
            'limit' => $limit,
        ], retryable: true);
        return Parse::jsonObject($body, 'campaign activity');
    }

    /**
     * Every page of one activity type, at the largest page size.
     *
     * @return list<array<string, mixed>>
     */
    public function activityAll(int $campaignId, string $type): array
    {
        $all = [];
        for ($page = 1; ; $page++) {
            $r = $this->activity($campaignId, $type, $page, self::MAX_ACTIVITY_PAGE);
            array_push($all, ...$r['activity']);
            if ($r['activity'] === [] || $page * self::MAX_ACTIVITY_PAGE >= $r['total']) {
                return $all;
            }
        }
    }

    /** @return array<string, string|int|null> */
    private static function recipientFields(array $r): array
    {
        return [
            'list_ids' => Shared::joinIds($r['listIds'] ?? null),
            'segment_ids' => Shared::joinIds($r['segmentIds'] ?? null),
            'exclude_list_ids' => Shared::joinIds($r['excludeListIds'] ?? null),
            'exclude_segment_ids' => Shared::joinIds($r['excludeSegmentIds'] ?? null),
            'dry_run' => Shared::flag($r['dryRun'] ?? false),
        ];
    }
}
