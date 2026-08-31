<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SafeDate;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\CalendarSpan;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Exceptions\InvalidReportPeriod;

final readonly class PeriodPresetResolver
{
    public function __construct(
        private PeriodQuery $periodQuery,
        private Clock $clock,
    ) {}

    public function resolve(string $preset, ?string $customFrom = null, ?string $customTo = null): Period
    {
        return match ($preset) {
            ReportPeriodPreset::ThisMonth->value => $this->periodQuery->current(),
            ReportPeriodPreset::Last3Months->value => $this->lastNMonths(3),
            ReportPeriodPreset::Last6Months->value => $this->lastNMonths(6),
            ReportPeriodPreset::Last12Months->value => $this->lastNMonths(12),
            ReportPeriodPreset::Ytd->value => $this->yearToDate(),
            ReportPeriodPreset::ThisYear->value => CalendarSpan::year($this->clock->now()),
            ReportPeriodPreset::Custom->value => $this->custom($customFrom, $customTo),
            default => throw new InvalidArgumentException("Unknown period preset: {$preset}"),
        };
    }

    // The current period plus the N-1 reached by walking PeriodQuery::previous(),
    // ending at the current window's own endExclusive.
    private function lastNMonths(int $months): Period
    {
        $current = $this->periodQuery->current();
        $earliest = $current;

        for ($i = 1; $i < $months; $i++) {
            $earliest = $this->periodQuery->previous($earliest);
        }

        return new Period(
            start: $earliest->start,
            endExclusive: $current->endExclusive,
            label: "Last {$months} months",
        );
    }

    // Stops at today because that is what "to date" means, and it is the only
    // reason this differs from `this_year`. Both once shared this formula on a
    // premise the ledger disproves: a future date DOES carry transactions, and
    // BookedFutureRowQuery reads them. Only the end is a different question.
    private function yearToDate(): Period
    {
        $now = $this->clock->now();

        return new Period(
            start: CalendarSpan::year($now)->start,
            endExclusive: $now->addDay()->startOfDay(),
            label: 'Year to date',
        );
    }

    // The picked end date is inclusive but Period.endExclusive is half-open, so
    // one day is added or that day's transactions vanish. customTo < customFrom
    // is rejected rather than resolved to a Period matching nothing.
    private function custom(?string $customFrom, ?string $customTo): Period
    {
        if ($customFrom === null || $customFrom === '' || $customTo === null || $customTo === '') {
            throw InvalidReportPeriod::incomplete();
        }

        $start = self::tryParseDate($customFrom) ?? throw InvalidReportPeriod::malformed('customFrom', $customFrom);
        $inclusiveEnd = self::tryParseDate($customTo) ?? throw InvalidReportPeriod::malformed('customTo', $customTo);

        if ($inclusiveEnd->lessThan($start)) {
            throw InvalidReportPeriod::inverted();
        }

        $endExclusive = $inclusiveEnd->addDay();

        return new Period(
            start: $start,
            endExclusive: $endExclusive,
            label: $start->toDateString().' → '.$inclusiveEnd->toDateString(),
        );
    }

    // The stored-definition factory asks this too, so a replayed blob and a
    // typed date are judged alike. The reading of "is this a day" itself is
    // SafeDate's, which is where every other surface takes it from.
    public static function tryParseDate(?string $value): ?CarbonImmutable
    {
        return $value === null ? null : SafeDate::dayOrNull($value);
    }
}
