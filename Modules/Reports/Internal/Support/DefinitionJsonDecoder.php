<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Support;

// Returns an empty array rather than throwing: a corrupt row must never break
// a read-side listing.
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
