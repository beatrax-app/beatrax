<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Carbon\CarbonImmutable;
use Modules\Ingestion\Public\Exceptions\InvalidAmountException;

/**
 * Parses ICS PDF date cells into a startOfDay `CarbonImmutable`.
 *
 * The startOfDay normalisation matches the project-wide
 * FingerprintComposer v3 day-precision invariant established by the
 * ASN CSV / CAMT.053 / MT940 adapters, so cross-format dedup hashes
 * remain comparable.
 *
 * Supported shapes (drawn from the Mijn ICS consumer-portal statement
 * fixture record):
 *
 *   - `12-04-2026`     → 2026-04-12 00:00:00
 *   - `15 februari 2026` → 2026-02-15 00:00:00   (full Dutch month name)
 *   - `12 apr 2026`    → 2026-04-12 00:00:00     (`j M Y` w/ Dutch abbrev)
 *   - `5 mei 2026`     → 2026-05-05 00:00:00
 *
 * Transaction-line shapes lack a year (`23 jan.` / `01 feb.`); the
 * adapter resolves the year from the surrounding statement header
 * before invoking this parser, so this parser only ever sees fully-
 * qualified strings.
 *
 * Stateless; no global locale mutation.
 */
final class IcsDateParser
{
    /** @var array<string, int> Dutch month abbreviations / full names → 1-12. */
    private const NL_MONTHS = [
        // Abbreviations (without the trailing period that ICS prints).
        'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4,
        'mei' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
        // Full names (statement-header shape).
        'januari' => 1, 'februari' => 2, 'maart' => 3, 'april' => 4,
        'juni' => 6, 'juli' => 7, 'augustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'december' => 12,
    ];

    public function parse(string $raw): CarbonImmutable
    {
        // Remove the trailing period that ICS prints after abbreviated
        // month names (`23 jan.`, `01 feb.`) so the token-form regex
        // below normalises across `jan` and `jan.` shapes. This also
        // catches the middle-of-string `jan.` in a fully-qualified
        // `23 jan. 2026` string emitted by some statement variants.
        $trimmed = trim(strtolower($raw));
        $trimmed = preg_replace('/([a-z]+)\./', '$1', $trimmed);
        if ($trimmed === null || $trimmed === '') {
            throw new InvalidAmountException('Empty date string.');
        }

        // Shape A: dd-mm-yyyy
        if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $trimmed, $m) === 1) {
            $parsed = CarbonImmutable::createFromFormat(
                '!d-m-Y',
                sprintf('%02d-%02d-%s', (int) $m[1], (int) $m[2], $m[3]),
            );

            if (! $parsed instanceof CarbonImmutable) {
                throw new InvalidAmountException(sprintf('Invalid Dutch date format: %s', $raw));
            }

            return $parsed->startOfDay();
        }

        // Shape B: `j M Y` / `j MMMM Y` with Dutch month abbreviations or full names.
        if (preg_match('/^(\d{1,2})\s+([a-z]+)\s+(\d{4})$/', $trimmed, $m) === 1) {
            $month = self::NL_MONTHS[$m[2]] ?? null;
            if ($month === null) {
                throw new InvalidAmountException(sprintf('Unknown Dutch month name: %s', $m[2]));
            }

            $day = (int) $m[1];
            $year = (int) $m[3];

            if ($day < 1 || $day > 31) {
                throw new InvalidAmountException(sprintf('Invalid day-of-month in Dutch date: %s', $raw));
            }

            $created = CarbonImmutable::create($year, $month, $day, 0, 0, 0);
            if (! $created instanceof CarbonImmutable) {
                throw new InvalidAmountException(sprintf('Invalid Dutch date: %s', $raw));
            }

            return $created->startOfDay();
        }

        throw new InvalidAmountException(sprintf('Invalid Dutch date format: %s', $raw));
    }
}
