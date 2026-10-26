<?php

namespace Skrybe\Resource;

use Skrybe\Internal\Errors;
use Skrybe\Internal\Parse;
use Skrybe\Internal\Transport;

/**
 * Every subscriber is in exactly one of STATUSES; `active` is who a campaign
 * sends to. A subscriber row has email, name, status, joined_at (Unix
 * seconds) and custom_fields by field name (dates as YYYY-MM-DD, empty values
 * null).
 */
final class Subscribers
{
    public const STATUSES = ['active', 'unconfirmed', 'unsubscribed', 'bounced', 'complained'];
    public const MAX_PAGE = 1000;

    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * Adds an address to a list. Posting one that is already there updates
     * its name and custom fields, and returns `already_subscribed` rather than
     * throwing, so re-running an import is safe.
     *
     * @param array{
     *     listId: string,
     *     email: string,
     *     name?: string,
     *     country?: string,
     *     ipAddress?: string,
     *     referrer?: string,
     *     gdpr?: bool,
     *     silent?: bool,
     *     fields?: array<string, string>,
     * } $params
     *     - country: two-letter ISO 3166-1 code, e.g. NG.
     *     - gdpr: record GDPR consent for an EU signup.
     *     - silent: add to a double opt-in list as single opt-in.
     *     - fields: custom fields keyed by the name the list shows, e.g.
     *       `Birthday` for `[Birthday,fallback=]`.
     * @return 'subscribed'|'already_subscribed'
     */
    public function subscribe(array $params): string
    {
        Errors::requireParams($params, ['listId', 'email']);

        $body = [];
        // Spaces are taken out, as the server matches on.
        foreach ($params['fields'] ?? [] as $name => $value) {
            $body[str_replace(' ', '', (string) $name)] = $value;
        }
        // Written after the custom fields so the documented names win a collision.
        $body['email'] = $params['email'];
        $body['list'] = $params['listId'];
        // Switches subscribe.php from rendering an HTML page to answering in plain text.
        $body['boolean'] = 'true';
        foreach (['name' => 'name', 'country' => 'country', 'ipAddress' => 'ipaddress', 'referrer' => 'referrer'] as $param => $field) {
            if (isset($params[$param])) {
                $body[$field] = $params[$param];
            }
        }
        if (!empty($params['gdpr'])) {
            $body['gdpr'] = 'true';
        }
        if (!empty($params['silent'])) {
            $body['silent'] = 'true';
        }

        // At the install root, not under api/.
        $text = $this->transport->text('subscribe', $body);
        if (strtolower(trim($text)) === 'already subscribed.') {
            return 'already_subscribed';
        }
        if (Errors::isProseSuccess($text)) {
            return 'subscribed';
        }
        throw Errors::unexpectedResponse('subscribe', $text);
    }

    /** Marks the address unsubscribed from the list. */
    public function unsubscribe(string $listId, string $email): void
    {
        $text = $this->transport->text('unsubscribe', ['email' => $email, 'list' => $listId, 'boolean' => 'true']);
        if (!Errors::isProseSuccess($text)) {
            throw Errors::unexpectedResponse('unsubscribe', $text);
        }
    }

    /** Removes the subscriber outright, rather than marking it unsubscribed. */
    public function delete(string $listId, string $email): void
    {
        $text = $this->transport->text('api/subscribers/delete.php', ['list_id' => $listId, 'email' => $email]);
        if (!Errors::isProseSuccess($text)) {
            throw Errors::unexpectedResponse('delete', $text);
        }
    }

    /**
     * One of Subscribed, Unsubscribed, Unconfirmed, Bounced, Soft bounced or
     * Complained. Returned as given, so a status added server-side surfaces
     * rather than being rejected.
     */
    public function status(string $listId, string $email): string
    {
        $text = $this->transport->text('api/subscribers/subscription-status.php', ['email' => $email, 'list_id' => $listId], retryable: true);
        $status = trim($text);
        if ($status === '') {
            throw Errors::unexpectedResponse('subscription-status', $text);
        }
        return $status;
    }

    /**
     * One page of a list's subscribers, oldest first.
     *
     * @param string|null $status one of STATUSES
     * @param int|null $limit at most 1000
     * @return array{list_id: string, status: ?string, page: int, limit: int, total: int, subscribers: list<array<string, mixed>>}
     */
    public function list(string $listId, ?string $status = null, ?int $page = null, ?int $limit = null): array
    {
        $body = $this->transport->text('api/subscribers/get-subscribers.php', [
            'list_id' => $listId,
            'status' => $status,
            'page' => $page,
            'limit' => $limit,
        ], retryable: true);
        return Parse::jsonObject($body, 'subscribers page');
    }

    /**
     * Every page, at the largest page size. Oldest first, so someone joining
     * mid-way lands on a later page instead of shifting the ones already read.
     *
     * @return list<array<string, mixed>>
     */
    public function listAll(string $listId, ?string $status = null): array
    {
        $all = [];
        for ($page = 1; ; $page++) {
            $r = $this->list($listId, $status, $page, self::MAX_PAGE);
            array_push($all, ...$r['subscribers']);
            if (count($r['subscribers']) < self::MAX_PAGE) {
                return $all;
            }
        }
    }
}
