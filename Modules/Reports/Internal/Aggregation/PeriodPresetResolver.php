<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Ledger\Public\Dto\Period;
use Modules\Ledger\Public\Services\PeriodQuery;

final class PeriodPresetResolver
{
    public function __construct(
        private readonly PeriodQuery $periodQuery,
        private readonly Clock $clock,
    ) {}

    public function resolve(string $preset, ?string $customFrom = null, ?string $customTo = null): Period
    {
        return match ($preset) {
            'this_month' => $this->periodQuery->current(),
            'last_3_months' => $this->lastNMonths(3),
            'last_6_months' => $this->lastNMonths(6),
            'last_12_months' => $this->lastNMonths(12),
            'ytd', 'this_year' => $this->yearWindow($preset),
            'custom' => $this->custom($customFrom, $customTo),
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
        $label = $preset === 'ytd' ? 'Year to date' : (string) $now->year;

        return new Period(start: $start, endExclusive: $endExclusive, label: $label);
    }

    // The picked end date is inclusive but Period.endExclusive is half-open, so
    // one day is added or that day's transactions vanish. customTo < customFrom
    // is rejected rather than resolved to a Period matching nothing.
    private function custom(?string $customFrom, ?string $customTo): Period
    {
        if ($customFrom === null || $customFrom === '' || $customTo === null || $customTo === '') {
            throw new InvalidArgumentException('The "custom" period preset requires both customFrom and customTo dates.');
        }

        $start = self::parseStrictDate($customFrom, 'customFrom');
        $inclusiveEnd = self::parseStrictDate($customTo, 'customTo');

        if ($inclusiveEnd->lessThan($start)) {
            throw new InvalidArgumentException('The "custom" period preset requires customTo to be on or after customFrom.');
        }

        $endExclusive = $inclusiveEnd->addDay();

        return new Period(
            start: $start,
            endExclusive: $endExclusive,
            label: $start->toDateString().' → '.$inclusiveEnd->toDateString(),
        );
    }

    // createFromFormat() normalizes an out-of-range day ("2026-02-30" becomes
    // 2026-03-02) rather than rejecting it, so the round-tripped format('Y-m-d')
    // is compared back against the raw input.
    private static function parseStrictDate(string $value, string $field): CarbonImmutable
    {
        try {
            $parsed = CarbonImmutable::createFromFormat('Y-m-d', $value);
        } catch (InvalidArgumentException) {
            $parsed = null;
        }

        if ($parsed === null || $parsed->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException("The \"{$field}\" date must be a valid \"Y-m-d\" date string, got: \"{$value}\".");
        }

        return $parsed->startOfDay();
    }
}
