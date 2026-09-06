<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

use DateTimeZone;

// The zone identifiers a reader may choose from, grouped by the region each
// one names. The whole database rather than a shortlist: a shortlist is a
// judgement about where readers live, and this is a local-first application
// with no idea where it was installed.
final class TimezoneOptions
{
    /**
     * @return array<string, list<string>> region => its identifiers, both sorted
     */
    public static function grouped(): array
    {
        $grouped = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $region = str_contains($identifier, '/') ? explode('/', $identifier)[0] : 'Other';
            $grouped[$region][] = $identifier;
        }

        ksort($grouped);

        foreach ($grouped as $region => $identifiers) {
            sort($identifiers);
            $grouped[$region] = $identifiers;
        }

        return $grouped;
    }

    // The city, with the underscores the database writes it with turned back
    // into spaces. The region is already the group heading, so repeating it on
    // every option would make the list read "Europe — Europe/Amsterdam".
    public static function label(string $identifier): string
    {
        $tail = implode(' — ', array_slice(explode('/', $identifier), 1));

        return str_replace('_', ' ', $tail === '' ? $identifier : $tail);
    }
}
