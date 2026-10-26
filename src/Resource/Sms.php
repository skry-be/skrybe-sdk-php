<?php

namespace Skrybe\Resource;

use Skrybe\Internal\Errors;
use Skrybe\Internal\Parse;
use Skrybe\Internal\Transport;

/**
 * `transactional` reaches numbers on Nigeria's DND register and is for OTPs,
 * receipts and alerts; `promotional` is the cheaper route DND numbers never get.
 */
final class Sms
{
    public const TYPES = ['transactional', 'promotional'];

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Sends one SMS, charged to the key's brand. Not retried: a timeout after
     * the server handed the message to the provider would otherwise send it twice.
     *
     * @param string $to any common Nigerian format: 0803 123 4567, +2348031234567, ...
     * @param 'transactional'|'promotional'|null $type
     * @return array{id: int, to: string, status: string, segments: int, error: ?string, allowance_remaining: ?int}
     *     status is `sent`, or `failed` when the provider refused it (the
     *     charge is refunded); allowance_remaining is null when the brand has no limit.
     */
    public function send(string $to, string $message, ?string $type = null): array
    {
        $body = $this->transport->text('api/sms/send.php', ['to' => $to, 'message' => $message, 'type' => $type]);
        return Parse::jsonObject($body, 'SMS send result');
    }

    /**
     * One message and its latest delivery status: queued, sent, delivered,
     * failed or rejected.
     *
     * @return array{id: int, to: string, sender_id: string, type: string, status: string, segments: int, error: ?string, created_at: int, sent_at: ?int, delivered_at: ?int}
     */
    public function get(int|string $messageId): array
    {
        $body = $this->transport->text('api/sms/get-message.php', ['id' => $messageId], retryable: true);
        return Parse::jsonObject($body, 'SMS message');
    }
}
