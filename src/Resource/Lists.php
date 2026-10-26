<?php

namespace Skrybe\Resource;

use Skrybe\Exception\ApiException;
use Skrybe\Internal\Errors;
use Skrybe\Internal\Parse;
use Skrybe\Internal\Transport;

/**
 * A list id is an encrypted value, not the number in the dashboard URL: pass
 * it back as given wherever a list id is wanted.
 *
 * get(), create(), update() and the custom-field methods return a list as the
 * API sends it: id, name, opt_in (single or double), subscribers {active,
 * unconfirmed, unsubscribed, bounced, complained} and custom_fields
 * [{name, type: Text|Date}] in storage order. `active` is who a campaign goes to.
 */
final class Lists
{
    /** @internal */
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * The brand's lists. An empty brand is an empty array, not an error.
     *
     * @return list<array{id: string, name: string}>
     */
    public function list(bool $includeHidden = false): array
    {
        $body = $this->transport->text('api/lists/get-lists.php', [
            'include_hidden' => $includeHidden ? 'yes' : 'no',
        ], retryable: true);
        if (Errors::isProseEmpty($body)) {
            return [];
        }
        return Parse::numberedObject($body, 'list');
    }

    /** @return array<string, mixed> */
    public function get(string $listId): array
    {
        $body = $this->transport->text('api/lists/get-list.php', ['list_id' => $listId], retryable: true);
        return Parse::jsonObject($body, 'list');
    }

    /**
     * Not retried: a repeat after a lost response would create a second list.
     *
     * @param 'single'|'double'|null $optIn
     * @return array<string, mixed>
     */
    public function create(string $name, ?string $optIn = null): array
    {
        $body = $this->transport->text('api/lists/create.php', ['name' => $name, 'opt_in' => $optIn]);
        return Parse::jsonObject($body, 'list');
    }

    /**
     * @param array{name?: string, optIn?: 'single'|'double'} $changes
     * @return array<string, mixed>
     */
    public function update(string $listId, array $changes): array
    {
        $body = $this->transport->text('api/lists/update-list.php', [
            'list_id' => $listId,
            'name' => $changes['name'] ?? null,
            'opt_in' => $changes['optIn'] ?? null,
        ]);
        return Parse::jsonObject($body, 'list');
    }

    /**
     * Deletes the list with its subscribers, segments, autoresponders and
     * rules. Refused while a scheduled or sending campaign is going to it.
     *
     * @return array{id: string, deleted: true}
     */
    public function delete(string $listId): array
    {
        $body = $this->transport->text('api/lists/delete.php', ['list_id' => $listId]);
        return Parse::jsonObject($body, 'delete result');
    }

    /**
     * Subscribers a campaign to this list would reach.
     *
     * Anything but a bare integer is a failure and is thrown, never reported
     * as 0: "this list is empty" is the one wrong answer a caller cannot detect.
     */
    public function activeSubscriberCount(string $listId): int
    {
        $body = $this->transport->text('api/subscribers/active-subscriber-count.php', ['list_id' => $listId], retryable: true);
        $trimmed = trim($body);
        if (!ctype_digit($trimmed)) {
            throw new ApiException('malformed_response', "Expected a subscriber count for list {$listId}, got: " . Errors::excerpt($trimmed), null, null, $body);
        }
        return (int) $trimmed;
    }

    /**
     * Adds a field; every subscriber gets an empty value for it. Returns the
     * list with its fields.
     *
     * @param 'text'|'date'|null $type defaults to text
     * @return array<string, mixed>
     */
    public function addCustomField(string $listId, string $name, ?string $type = null): array
    {
        $body = $this->transport->text('api/lists/add-custom-field.php', [
            'list_id' => $listId,
            'name' => $name,
            'type' => $type,
        ]);
        return Parse::jsonObject($body, 'list');
    }

    /**
     * Renames a field, keeping subscribers' values. The autoresponders and
     * segment conditions that use it follow; tags already in campaigns keep
     * the old name.
     *
     * @return array<string, mixed>
     */
    public function renameCustomField(string $listId, string $name, string $newName): array
    {
        $body = $this->transport->text('api/lists/rename-custom-field.php', [
            'list_id' => $listId,
            'name' => $name,
            'new_name' => $newName,
        ]);
        return Parse::jsonObject($body, 'list');
    }

    /**
     * Deletes a field and every subscriber's value for it. Refused while an
     * autoresponder or segment uses it.
     *
     * @return array<string, mixed>
     */
    public function deleteCustomField(string $listId, string $name): array
    {
        $body = $this->transport->text('api/lists/delete-custom-field.php', ['list_id' => $listId, 'name' => $name]);
        return Parse::jsonObject($body, 'list');
    }
}
