<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Carbon\CarbonImmutable;
use Modules\Ingestion\Internal\Exceptions\InvalidAmountException;

final class IcsDateParser
{
    /** @var array<string, int> */
    private const NL_MONTHS = [
        // Abbreviations, stored without the trailing period ICS prints.
        'jan' => 1, 'feb' => 2, 'mrt' => 3, 'apr' => 4,
        'mei' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8,
        'sep' => 9, 'okt' => 10, 'nov' => 11, 'dec' => 12,
        // Full names, as used in the statement-header date.
        'januari' => 1, 'februari' => 2, 'maart' => 3, 'april' => 4,
        'juni' => 6, 'juli' => 7, 'augustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'december' => 12,
    ];

    public function parse(string $raw): CarbonImmutable
    {
        // Drop the period ICS prints after an abbreviated month ("23 jan."),
        // including mid-string in "23 jan. 2026".
        $trimmed = trim(strtolower($raw));
        $trimmed = preg_replace('/([a-z]+)\./', '$1', $trimmed);
        if ($trimmed === null || $trimmed === '') {
            throw new InvalidAmountException('Empty date string.');
        }

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

        // The statement-header date: day + Dutch month name + year.
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
