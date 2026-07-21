<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

// Tolerates malformed/non-JSON input by returning an empty array rather
// than throwing — a corrupt row must never break a read-side listing.
// Shared by PinnedReportsQuery and SavedReportsQuery.
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
