<?php

namespace Skrybe\Internal;

use Skrybe\Exception\ApiException;

/**
 * Parsers for the API's response bodies.
 *
 * Older installs build `api/lists/get-lists.php` and `api/brands/get-brands.php`
 * by string concatenation: entries keyed `list1`, `list2`, ... rather than an
 * array, and names not escaped, so a list called `Q4 "Big" Push` emits invalid
 * JSON. The id is safe to anchor on: encrypt_val() never produces a quote.
 *
 * @internal
 */
final class Parse
{
    /**
     * Parse a `{"list1": {...}, "list2": {...}}` payload into an ordered list.
     *
     * @param string $prefix the key prefix the endpoint uses: `list` or `brand`
     * @return list<array{id: string, name: string}>
     */
    public static function numberedObject(string $body, string $prefix): array
    {
        $trimmed = trim($body);
        if ($trimmed === '') {
            return [];
        }

        $parsed = self::decodeObject($trimmed);
        if ($parsed !== null) {
            $rows = [];
            $index = 0;
            foreach ($parsed as $key => $value) {
                $key = (string) $key;
                if (!str_starts_with($key, $prefix) || !is_array($value)) {
                    continue;
                }
                $suffix = substr($key, strlen($prefix));
                $rows[] = [
                    'sort' => ctype_digit($suffix) ? (int) $suffix : $index,
                    'row' => ['id' => (string) ($value['id'] ?? ''), 'name' => (string) ($value['name'] ?? '')],
                ];
                $index++;
            }
            usort($rows, fn (array $a, array $b) => $a['sort'] <=> $b['sort']);
            return array_column($rows, 'row');
        }

        // Almost certainly an unescaped quote in a name. The trailing lookahead
        // is what lets an embedded quote survive: the lazy name match can only
        // stop at a `"` followed by the entry's closing brace AND either the
        // next numbered key or the end of the object.
        $p = preg_quote($prefix, '/');
        $entry = '/"' . $p . '\d+"\s*:\s*\{\s*"id"\s*:\s*"([^"]*)"\s*,\s*"name"\s*:\s*"([\s\S]*?)"\s*\}'
            . '(?=\s*,\s*"' . $p . '\d+"\s*:|\s*\}\s*$)/';
        if (preg_match_all($entry, $trimmed, $matches, PREG_SET_ORDER) > 0) {
            return array_map(fn (array $m) => ['id' => $m[1], 'name' => $m[2]], $matches);
        }

        // The body is the whole diagnosis here: usually a PHP warning or an
        // interstitial from something in front of the install.
        throw new ApiException(
            'malformed_response',
            "Could not parse the {$prefix} payload returned by the API.",
            'The server sent: ' . Errors::excerpt($trimmed),
            null,
            $body,
        );
    }

    /**
     * Parse a numbered payload from an endpoint that builds it with
     * json_encode() (`api/campaigns/get-campaigns.php`), so rows are kept whole.
     *
     * @return list<array<string, mixed>>
     */
    public static function numberedRecords(string $body, string $prefix): array
    {
        $parsed = self::jsonObject($body, "{$prefix} payload");
        $rows = [];
        foreach ($parsed as $key => $value) {
            if (preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $key, $m) === 1 && is_array($value)) {
                $rows[] = ['n' => (int) $m[1], 'row' => $value];
            }
        }
        usort($rows, fn (array $a, array $b) => $a['n'] <=> $b['n']);
        return array_column($rows, 'row');
    }

    /**
     * Parse a body that must be a JSON object, e.g. `api/campaigns/stats.php`.
     * Anything else is almost always a PHP warning or a proxy page, so the body
     * itself goes in the hint.
     *
     * @return array<string, mixed>
     */
    public static function jsonObject(string $body, string $what): array
    {
        $trimmed = trim($body);
        $parsed = self::decodeObject($trimmed);
        if ($parsed !== null) {
            return $parsed;
        }
        throw new ApiException(
            'malformed_response',
            "Could not parse the {$what} returned by the API.",
            'The server sent: ' . Errors::excerpt($trimmed),
            null,
            $body,
        );
    }

    /**
     * Decode a JSON object to an associative array; null for anything else,
     * including a JSON array, which an associative decode cannot tell apart.
     *
     * @return array<string, mixed>|null
     */
    private static function decodeObject(string $json): ?array
    {
        $shape = json_decode($json);
        if (!$shape instanceof \stdClass) {
            return null;
        }
        $assoc = json_decode($json, true);
        return is_array($assoc) ? $assoc : null;
    }
}
