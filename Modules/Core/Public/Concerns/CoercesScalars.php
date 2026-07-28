<?php

declare(strict_types=1);

namespace Modules\Core\Public\Concerns;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
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
}
