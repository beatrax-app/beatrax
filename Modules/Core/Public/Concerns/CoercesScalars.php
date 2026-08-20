<?php

declare(strict_types=1);

namespace Modules\Core\Public\Concerns;

trait CoercesScalars
{
    // SQLite hands query-builder columns back as strings on stdClass, and
    // json_decode hands back mixed, so anything read that way needs a
    // narrowing step before it can satisfy a typed property. Non-numeric
    // input collapses to 0 rather than raising: the callers are read paths.
    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
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
