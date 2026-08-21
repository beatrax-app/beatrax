<?php

declare(strict_types=1);

namespace Modules\Core\Public\Concerns;

trait CoercesScalars
{
    // SQLite hands query-builder columns back as strings on stdClass, and
    // json_decode hands back mixed, so anything read that way needs a
    // narrowing step before it can satisfy a typed property. Non-numeric
    // input collapses to $default rather than raising: the callers are read paths.
    private static function toInt(mixed $value, int $default = 0): int
    {
        return is_numeric($value) ? (int) $value : $default;
    }

    // For foreign keys and row ids, where 0 and a negative are as absent as
    // null is — a caller that gets an int back can use it as an id without a
    // second range check.
    private static function toPositiveIntOrNull(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    // Objects and arrays have no useful string form here, so they collapse
    // to the empty string instead of tripping a conversion error. A string
    // returns unchanged rather than round-tripping through a cast.
    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }

    // The nullable counterpart: null survives as null rather than becoming
    // the empty string, so a caller can tell an absent value from a blank
    // one. Objects and arrays still collapse, because neither is a string.
    private static function toStringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : null;
    }
}
