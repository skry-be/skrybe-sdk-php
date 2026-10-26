<?php

namespace Skrybe\Resource;

/**
 * @internal
 */
final class Shared
{
    /** Comma-joined, or null for an empty or missing list so the field is not sent. */
    public static function joinIds(?array $ids): ?string
    {
        return $ids ? implode(',', $ids) : null;
    }

    /** `1` when set, so a false flag leaves the field out rather than sending `0`. */
    public static function flag(mixed $value): ?int
    {
        return $value ? 1 : null;
    }
}
