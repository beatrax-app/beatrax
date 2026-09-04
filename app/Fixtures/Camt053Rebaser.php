<?php

declare(strict_types=1);

namespace App\Fixtures;

use Carbon\CarbonImmutable;
use DateTimeZone;
use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Public\Support\SafeDate;

/**
 * @link ../../.docs/local_development/rebasing-a-statement-fixture.md#camt053
 */
final class Camt053Rebaser implements RebasesStatementDates
{
    private const string FORMAT = 'camt053';

    private const string ISO_DAY = 'Y-m-d';

    // Every camt.053 element whose body is a date or a date-time: <Dt> under
    // BookgDt/ValDt/Bal, and the four DtTm carriers around them.
    private const string ELEMENTS = '/<(Dt|DtTm|CreDtTm|FrDtTm|ToDtTm)>([^<]+)<\/\1>/';

    private const string DAY_BODY = '/^\d{4}-\d{2}-\d{2}$/';

    private const string ENTRY_DATES = '/<(?:BookgDt|ValDt)>\s*<Dt>(?<day>\d{4}-\d{2}-\d{2})<\/Dt>/';

    private const string DATE_TIME_BODY = '/^(?<day>\d{4}-\d{2}-\d{2})(?<time>T[^+\-Z]+)(?<offset>Z|[+\-]\d{2}:\d{2})?$/';

    // The zone the ASN export writes its offsets in. A shift of whole months can
    // cross a summer-time boundary, and leaving +02:00 on a January date would
    // move the recorded instant by an hour once the adapter normalises it to UTC.
    private const string EXPORT_ZONE = 'Europe/Amsterdam';

    public function handles(string $path, string $contents): bool
    {
        return preg_match('/\.xml$/i', $path) === 1
            && str_contains($contents, 'camt.053')
            && str_contains($contents, '<BkToCstmrStmt>');
    }

    public function format(string $contents): string
    {
        return self::FORMAT;
    }

    // Entry dates only. The header CreDtTm and the closing balance both sit after
    // the last entry, and anchoring on either lands the ENTRIES a month short of
    // the window they were shifted to reach.
    public function newestDate(string $contents): ?CarbonImmutable
    {
        $matches = PatternScan::all(self::ENTRY_DATES, $contents);

        $newest = null;
        foreach ($matches['day'] as $raw) {
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
            throw new StatementRebaseFailed('No camt.053 date element found; nothing to rebase.');
        }

        $after = [];
        $rebased = preg_replace_callback(
            self::ELEMENTS,
            function (array $m) use ($shift, &$after): string {
                $body = $this->shiftBody($m[2], $shift, $after);

                return sprintf('<%s>%s</%1$s>', $m[1], $body);
            },
            $contents,
        );

        if ($rebased === null) {
            throw new StatementRebaseFailed('Could not rewrite the camt.053 date elements.');
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

    /**
     * @param  list<CarbonImmutable>  $after
     */
    private function shiftBody(string $body, MonthShift $shift, array &$after): string
    {
        $day = $this->asDay($body);
        if ($day instanceof CarbonImmutable) {
            $shifted = $shift->apply($day);
            $after[] = $shifted;

            return $shifted->format(self::ISO_DAY);
        }

        // A body that carries no date, and one whose date will not parse, are
        // the same answer: it is left exactly as it arrived.
        $matched = preg_match(self::DATE_TIME_BODY, $body, $parts) === 1;
        $day = $matched ? $this->asDay($parts['day']) : null;

        if (! $day instanceof CarbonImmutable) {
            return $body;
        }

        $shifted = $shift->apply($day);
        $after[] = $shifted;

        return $shifted->format(self::ISO_DAY)
            .$parts['time']
            .$this->offset($day, $shifted, $parts['offset'] ?? '');
    }

    // Only an offset this export's own zone explains is recomputed; anything
    // else is another writer's convention and is left as it stands.
    private function offset(CarbonImmutable $before, CarbonImmutable $after, string $offset): string
    {
        if ($offset === '' || $offset === 'Z') {
            return $offset;
        }

        $zone = new DateTimeZone(self::EXPORT_ZONE);
        if ($before->setTimezone($zone)->format('P') !== $offset) {
            return $offset;
        }

        return $after->setTimezone($zone)->format('P');
    }

    /**
     * @return list<CarbonImmutable>
     */
    private function days(string $contents): array
    {
        $matches = PatternScan::all(self::ELEMENTS, $contents);

        $days = [];
        foreach ($matches[2] as $body) {
            $day = $this->asDay(preg_match(self::DATE_TIME_BODY, $body, $parts) === 1 ? $parts['day'] : $body);
            if ($day instanceof CarbonImmutable) {
                $days[] = $day;
            }
        }

        return $days;
    }

    private function asDay(string $raw): ?CarbonImmutable
    {
        if (preg_match(self::DAY_BODY, $raw) !== 1) {
            return null;
        }

        return SafeDate::fromFormatOrNull('!'.self::ISO_DAY, $raw);
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

        return $found ?? throw new StatementRebaseFailed('The camt.053 rebase rewrote no dates.');
    }
}
