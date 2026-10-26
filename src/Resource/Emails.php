<?php

namespace Skrybe\Resource;

use Skrybe\Exception\InvalidRequestException;
use Skrybe\Internal\Errors;
use Skrybe\Internal\Transport;

final class Emails
{
    /** send.php refuses more than this, and says so with a 400. */
    public const MAX_RECIPIENTS = 250;

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * `api/emails/send.php`: a bulk send to addresses or lists. It creates a
     * campaign behind the scenes; the subject doubles as its title.
     *
     * @param array{
     *     fromName: string,
     *     fromEmail: string,
     *     replyTo: string,
     *     subject: string,
     *     htmlText: string,
     *     plainText?: string,
     *     to?: list<string>,
     *     recipientVariables?: array<string, array<string, mixed>>,
     *     listIds?: list<string>,
     *     queryString?: string,
     *     trackOpens?: int,
     *     trackClicks?: int,
     *     scheduleDateTime?: string,
     *     scheduleTimezone?: string,
     * } $params
     *     - to: at most 250 addresses. Without `listIds` these are added to a new list.
     *     - recipientVariables: per-recipient values keyed by address, referenced
     *       in the body as `[recipient.first]`.
     *     - listIds: encrypted list ids, as lists->list() returns them.
     *     - trackOpens, trackClicks: 0 off, 1 on, 2 anonymous.
     *     - scheduleDateTime: e.g. "June 15, 2027 6:05pm"; scheduleTimezone
     *       e.g. "Africa/Lagos", defaulting to the brand's.
     * @return array{message: string, campaignId: ?string}
     */
    public function send(array $params): array
    {
        Errors::requireParams($params, ['fromName', 'fromEmail', 'replyTo', 'subject', 'htmlText']);
        $to = $params['to'] ?? [];
        if ($to === [] && ($params['listIds'] ?? []) === []) {
            throw new InvalidRequestException('recipients_missing', 'Nothing to send to.', "Pass 'to' addresses, or 'listIds'.");
        }
        // Checked here so a 251-address send fails before it is transmitted.
        if (count($to) > self::MAX_RECIPIENTS) {
            throw new InvalidRequestException(
                'too_many_recipients',
                count($to) . ' recipients is more than the ' . self::MAX_RECIPIENTS . ' this endpoint accepts.',
                "Split the send, or put the addresses on a list and pass 'listIds'.",
            );
        }

        $data = $this->transport->json('api/emails/send.php', [
            'from_name' => $params['fromName'],
            'from_email' => $params['fromEmail'],
            'reply_to' => $params['replyTo'],
            'subject' => $params['subject'],
            'html_text' => $params['htmlText'],
            'plain_text' => $params['plainText'] ?? null,
            // Both go over the wire as JSON strings inside a form body.
            'to' => $to !== [] ? self::encode(array_values($to)) : null,
            'recipient-variables' => !empty($params['recipientVariables']) ? self::encode($params['recipientVariables']) : null,
            'list_ids' => Shared::joinIds($params['listIds'] ?? null),
            'query_string' => $params['queryString'] ?? null,
            'track_opens' => $params['trackOpens'] ?? null,
            'track_clicks' => $params['trackClicks'] ?? null,
            'schedule_date_time' => $params['scheduleDateTime'] ?? null,
            'schedule_timezone' => $params['scheduleTimezone'] ?? null,
        ]);
        return [
            'message' => is_string($data['message'] ?? null) ? $data['message'] : 'Sent.',
            'campaignId' => isset($data['data']['campaign_id']) ? (string) $data['data']['campaign_id'] : null,
        ];
    }

    /**
     * `api/emails/send-transactional.php`: one email, delivered immediately
     * with nothing queued. For OTPs, password resets and receipts.
     *
     * @param array{
     *     toEmail: string,
     *     subject: string,
     *     htmlBody?: string,
     *     plainBody?: string,
     *     fromName?: string,
     *     fromEmail?: string,
     *     replyTo?: string,
     * } $params at least one of htmlBody and plainBody; the sender fields fall
     *     back to the brand's configured values.
     * @return array{message: string, messageId: ?string}
     */
    public function sendTransactional(array $params): array
    {
        Errors::requireParams($params, ['toEmail', 'subject']);
        if (empty($params['htmlBody']) && empty($params['plainBody'])) {
            throw new InvalidRequestException('body_missing', 'A message body is required.', "Pass 'htmlBody', 'plainBody', or both.");
        }

        // The one endpoint that reads php://input: a form post gets "Invalid JSON payload."
        $data = $this->transport->json('api/emails/send-transactional.php', [
            'to_email' => $params['toEmail'],
            'subject' => $params['subject'],
            'html_body' => $params['htmlBody'] ?? null,
            'plain_body' => $params['plainBody'] ?? null,
            'from_name' => $params['fromName'] ?? null,
            'from_email' => $params['fromEmail'] ?? null,
            'reply_to' => $params['replyTo'] ?? null,
        ], encoding: 'json');
        return [
            'message' => is_string($data['message'] ?? null) ? $data['message'] : 'Email sent successfully.',
            'messageId' => isset($data['message_id']) ? (string) $data['message_id'] : null,
        ];
    }

    private static function encode(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
