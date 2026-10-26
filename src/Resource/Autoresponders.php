<?php

namespace Skrybe\Resource;

use Skrybe\Internal\Errors;
use Skrybe\Internal\Parse;
use Skrybe\Internal\Transport;

/**
 * `drip` sends each email a set time after someone joins; `annually` and
 * `on_date` send on a Date custom field, every year or once.
 *
 * An autoresponder has id, name, type (or `unknown` for a type this SDK
 * predates), list_id (encrypted), custom_field, emails and enabled_emails. An
 * email has id, autoresponder_id, subject, from_name, from_email, when
 * ("immediately", "+3 days", "-1 days", or "" for the date itself), enabled,
 * recipients and created_at.
 */
final class Autoresponders
{
    public const TYPES = ['drip', 'annually', 'on_date'];

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * The brand's autoresponders, or one list's.
     *
     * @return list<array<string, mixed>>
     */
    public function list(?string $listId = null): array
    {
        $body = $this->transport->text('api/autoresponders/get-autoresponders.php', ['list_id' => $listId], retryable: true);
        return Parse::jsonObject($body, 'autoresponders')['autoresponders'] ?? [];
    }

    /**
     * One autoresponder with its emails, under `email_list`.
     *
     * @return array<string, mixed>
     */
    public function get(int $autoresponderId): array
    {
        $body = $this->transport->text('api/autoresponders/get-autoresponder-emails.php', ['autoresponder_id' => $autoresponderId], retryable: true);
        return Parse::jsonObject($body, 'autoresponder');
    }

    /**
     * Engagement for one autoresponder email, shaped like campaigns->stats().
     *
     * @return array<string, mixed>
     */
    public function emailStats(int $emailId): array
    {
        $body = $this->transport->text('api/autoresponders/autoresponder-stats.php', ['email_id' => $emailId], retryable: true);
        return Parse::jsonObject($body, 'autoresponder email stats');
    }

    /**
     * Creates an autoresponder with no emails; add them with createEmail().
     * Not retried.
     *
     * @param array{name: string, type: 'drip'|'annually'|'on_date', listId: string, customField?: string} $params
     *     A list can have one drip autoresponder. customField is required for
     *     annually and on_date: a Date custom field of the list, one
     *     autoresponder per field.
     * @return array<string, mixed>
     */
    public function create(array $params): array
    {
        Errors::requireParams($params, ['name', 'type', 'listId']);
        $body = $this->transport->text('api/autoresponders/create-autoresponder.php', [
            'name' => $params['name'],
            'type' => $params['type'],
            'list_id' => $params['listId'],
            'custom_field' => $params['customField'] ?? null,
        ]);
        return Parse::jsonObject($body, 'autoresponder');
    }

    /**
     * Adds an email to an autoresponder. Not retried: a repeat would add it twice.
     *
     * @param array{
     *     subject: string,
     *     htmlText: string,
     *     plainText?: string,
     *     preheader?: string,
     *     when?: string,
     *     fromName?: string,
     *     fromEmail?: string,
     *     replyTo?: string,
     *     queryString?: string,
     *     trackOpens?: int,
     *     trackClicks?: int,
     *     enabled?: bool,
     * } $params
     *     - when: for a drip, "immediately" (the default) or a delay such as
     *       "+3 days"; for annually and on_date, "on" (the default) or an
     *       offset such as "+9 hours" or "-1 days". Units: minute, hour, day,
     *       week, month.
     *     - fromName, fromEmail, replyTo: each falls back to the brand's sender.
     *     - enabled: emails are created off, as the dashboard creates them,
     *       unless this is true.
     * @return array<string, mixed>
     */
    public function createEmail(int $autoresponderId, array $params): array
    {
        Errors::requireParams($params, ['subject', 'htmlText']);
        $body = $this->transport->text('api/autoresponders/create-autoresponder-email.php', [
            'autoresponder_id' => $autoresponderId,
            'subject' => $params['subject'],
            'html_text' => $params['htmlText'],
            'plain_text' => $params['plainText'] ?? null,
            'preheader' => $params['preheader'] ?? null,
            'when' => $params['when'] ?? null,
            'from_name' => $params['fromName'] ?? null,
            'from_email' => $params['fromEmail'] ?? null,
            'reply_to' => $params['replyTo'] ?? null,
            'query_string' => $params['queryString'] ?? null,
            'track_opens' => $params['trackOpens'] ?? null,
            'track_clicks' => $params['trackClicks'] ?? null,
            'enabled' => Shared::flag($params['enabled'] ?? false),
        ]);
        return Parse::jsonObject($body, 'autoresponder email');
    }
}
