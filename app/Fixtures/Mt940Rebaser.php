<?php

declare(strict_types=1);

namespace App\Fixtures;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ingestion\Public\Banking\SwiftDate;

/**
 * @link ../../.docs/local_development/rebasing-a-statement-fixture.md#mt940
 */
final class Mt940Rebaser implements RebasesStatementDates
{
    private const string FORMAT = 'mt940';

    private const string SWIFT_DAY = 'ymd';

    private const string BALANCE_LINE = '/^:(60F|60M|62F|62M|64|65):([CD])(\d{6})([A-Z]{3})/m';

    // The same anchoring Mt940Tag61Parser uses: the optional four-digit entry
    // date is only unambiguous because the status letter has to follow it.
    private const string STATEMENT_LINE = '/^:61:(\d{6})(\d{4})?(R?[CD])/m';

    public function handles(string $path, string $contents): bool
    {
        return preg_match('/\.(sta|940|mt940)$/i', $path) === 1
            && preg_match(self::STATEMENT_LINE, $contents) === 1;
    }

    public function format(string $contents): string
    {
        return self::FORMAT;
    }

    // Statement lines only. The :62F: closing balance carries the statement's end
    // date, which sits after the last entry, and anchoring on it lands the
    // ENTRIES a month short of the window they were shifted to reach.
    public function newestDate(string $contents): ?CarbonImmutable
    {
        $matches = PatternScan::all(self::STATEMENT_LINE, $contents);

        $newest = null;
        foreach ($matches[1] as $raw) {
            $day = $this->asDay($raw);
            if ($day instanceof CarbonImmutable && ($newest === null || $day->greaterThan($newest))) {
                $newest = $day;
            }
        }

        return $newest;
    }

    public function rebase(string $contents, MonthShift $shift): StatementRebaseResult
    {
        $before = $this->days($contents);
        if ($before === []) {
            throw new StatementRebaseFailed('No MT940 statement or balance line found; nothing to rebase.');
        }

        $after = [];

        $rebased = preg_replace_callback(
            self::BALANCE_LINE,
            function (array $m) use ($shift, &$after): string {
                $day = $this->asDay($m[3]);
                if (! $day instanceof CarbonImmutable) {
                    return $m[0];
                }

                $shifted = $shift->apply($day);
                $after[] = $shifted;

                return sprintf(':%s:%s%s%s', $m[1], $m[2], $shifted->format(self::SWIFT_DAY), $m[4]);
            },
            $contents,
        );

        $rebased = $rebased === null ? null : preg_replace_callback(
            self::STATEMENT_LINE,
            function (array $m) use ($shift, &$after): string {
                $value = $this->asDay($m[1]);
                if (! $value instanceof CarbonImmutable) {
                    return $m[0];
                }

                $shiftedValue = $shift->apply($value);
                $after[] = $shiftedValue;

                $entry = $m[2] === '' ? '' : $this->shiftEntryDate($value, $m[2], $shift);

                return sprintf(':61:%s%s%s', $shiftedValue->format(self::SWIFT_DAY), $entry, $m[3]);
            },
            $rebased,
        );

        if ($rebased === null) {
            throw new StatementRebaseFailed('Could not rewrite the MT940 date fields.');
        }

        return new StatementRebaseResult(
            contents: $rebased,
            format: self::FORMAT,
            months: $shift->months,
            oldestBefore: $this->extreme($before, earliest: true),
            newestBefore: $this->extreme($before, earliest: false),
            oldestAfter: $this->extreme($after, earliest: true),
            newestAfter: $this->extreme($after, earliest: false),
            datesRewritten: count($after),
        );
    }

    private function shiftEntryDate(CarbonImmutable $value, string $monthDay, MonthShift $shift): string
    {
        $month = (int) substr($monthDay, 0, 2);
        $day = (int) substr($monthDay, 2, 2);
        $year = $value->year + SwiftDate::entryYearOffset($month, $value->month);

        $entry = SafeDate::fromFormatOrNull('!Y-m-d', sprintf('%04d-%02d-%02d', $year, $month, $day));

        return $entry instanceof CarbonImmutable ? $shift->apply($entry)->format('md') : $monthDay;
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function days(string $contents): array
    {
        $days = [];

        foreach ([self::BALANCE_LINE => 3, self::STATEMENT_LINE => 1] as $pattern => $group) {
            $matches = PatternScan::all($pattern, $contents);
            foreach ($matches[$group] as $raw) {
                $day = $this->asDay($raw);
                if ($day instanceof CarbonImmutable) {
                    $days[] = $day;
                }
            }
        }

        return $days;
    }

    // Read through SwiftDate, the same seam the import reads it through: a
    // rebased fixture is only ever a parser input, so a second reading here
    // would hand the parser a file it dates differently from the rebaser.
    private function asDay(string $raw): ?CarbonImmutable
    {
        if (preg_match('/^\d{6}$/', $raw) !== 1) {
            return null;
        }

        return SafeDate::fromFormatOrNull(
            '!Y-m-d',
            sprintf('%04d-%s-%s', SwiftDate::yearFor((int) substr($raw, 0, 2)), substr($raw, 2, 2), substr($raw, 4, 2)),
        );
    }

    /**
     * @param  list<CarbonImmutable>  $days
     */
    private function extreme(array $days, bool $earliest): CarbonImmutable
    {
        $found = null;
        foreach ($days as $day) {
            $wins = $found === null || ($earliest ? $day->lessThan($found) : $day->greaterThan($found));
            if ($wins) {
                $found = $day;
            }
        }

        return $found ?? throw new StatementRebaseFailed('The MT940 rebase rewrote no dates.');
    }
}
