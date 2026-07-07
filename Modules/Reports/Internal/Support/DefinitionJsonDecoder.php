<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

/**
 * Decodes a `saved_reports.definition` JSON column value into a
 * string-keyed array, tolerating malformed/non-JSON input by returning an
 * empty array rather than throwing (Rule 2 — a corrupt row must never break
 * a read-side listing).
 *
 * Extracted (IN-02) from the identical private `decodeDefinition()` methods
 * previously duplicated verbatim in `PinnedReportsQuery` and
 * `SavedReportsQuery` — both now call this single implementation.
 */
final class DefinitionJsonDecoder
{
    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $raw): array
    {
        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $result */
        $result = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
