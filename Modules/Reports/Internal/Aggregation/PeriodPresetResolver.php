<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;
use Modules\Reports\Internal\Enums\ReportPeriodPreset;
use Modules\Reports\Internal\Exceptions\InvalidReportPeriod;

final class PeriodPresetResolver
{
    public function __construct(
        private readonly PeriodQuery $periodQuery,
        private readonly Clock $clock,
    ) {}

    public function resolve(string $preset, ?string $customFrom = null, ?string $customTo = null): Period
    {
        return match ($preset) {
            ReportPeriodPreset::ThisMonth->value => $this->periodQuery->current(),
            ReportPeriodPreset::Last3Months->value => $this->lastNMonths(3),
            ReportPeriodPreset::Last6Months->value => $this->lastNMonths(6),
            ReportPeriodPreset::Last12Months->value => $this->lastNMonths(12),
            ReportPeriodPreset::Ytd->value, ReportPeriodPreset::ThisYear->value => $this->yearWindow($preset),
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

    // 'ytd' and 'this_year' both resolve to startOfYear() -> now+1day, since a
    // future date carries no transactions. Two keys purely for the picker's
    // copy; the formula must not diverge between them.
    private function yearWindow(string $preset): Period
    {
        $now = $this->clock->now();
        $start = $now->startOfYear()->startOfDay();
        $endExclusive = $now->addDay()->startOfDay();
        $label = $preset === ReportPeriodPreset::Ytd->value ? 'Year to date' : (string) $now->year;

        return new Period(start: $start, endExclusive: $endExclusive, label: $label);
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

    // The one spelling of "is this a Y-m-d date": the stored-definition factory
    // asks it too, so a replayed blob and a typed date are judged alike.
    // createFromFormat() normalizes an out-of-range day ("2026-02-30" becomes
    // 2026-03-02), so the round-trip is compared back against the raw input.
    public static function tryParseDate(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (InvalidArgumentException) {
            return null;
        }

        return $parsed !== null && $parsed->format('Y-m-d') === $value ? $parsed->startOfDay() : null;
    }
}
