<?php

declare(strict_types=1);

namespace Modules\Calendar\Internal\Services;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Enums\ForecastHorizon;

// Which month the calendar may show, how far into it the grid runs, and the
// two bounds it clamps to. The arrows, the URL parameters, the header's
// disabled states and the empty state read one answer from here.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-window-recomputed-instead-of-derived
 */
final readonly class CalendarMonthWindow
{
    // The projection the balance line is drawn from. A cell past its last
    // point has no figure to state and falls to the computing sentinel, which
    // is why this is the supply the forward reach is cut to rather than a
    // preference: DailyBalanceAggregator asks for exactly this horizon.
    public const ForecastHorizon PROJECTION = ForecastHorizon::OneYear;

    // How far back it will render. Past days come from real transactions, not
    // a projection, so this is a stop for the backward walk rather than a
    // horizon: ten years is past the reach of the statement exports the ledger
    // is built from, so it never hides a month the reader has rows in.
    public const int HISTORY_MONTHS = 120;

    private const int FIRST_MONTH = 1;

    private const int LAST_MONTH = 12;

    public function __construct(
        private Clock $clock,
    ) {}

    // A tampered ?year=&month= is clamped to the same two bounds the arrows
    // enforce rather than refused, so the reader always lands on a month that
    // exists inside the window.
    /**
     * @return array{year: int, month: int}
     */
    public function display(?int $year, ?int $month): array
    {
        $now = $this->clock->now();
        $displayYear = $year ?? $now->year;
        $displayMonth = ($month !== null && $month >= self::FIRST_MONTH && $month <= self::LAST_MONTH)
            ? $month
            : $now->month;

        $ceiling = $this->ceilingMonth();
        if (self::isAfter($displayYear, $displayMonth, $ceiling)) {
            return ['year' => $ceiling->year, 'month' => $ceiling->month];
        }

        $floor = $this->floorMonth();
        if (self::isBefore($displayYear, $displayMonth, $floor)) {
            return ['year' => $floor->year, 'month' => $floor->month];
        }

        return ['year' => $displayYear, 'month' => $displayMonth];
    }

    /**
     * @return array{year: int, month: int}|null null when the step would leave the window
     */
    public function previous(int $year, int $month): ?array
    {
        $previous = self::firstOf($year, $month)->subMonthNoOverflow();

        return self::isBefore($previous->year, $previous->month, $this->floorMonth())
            ? null
            : ['year' => $previous->year, 'month' => $previous->month];
    }

    /**
     * @return array{year: int, month: int}|null null when the step would leave the window
     */
    public function next(int $year, int $month): ?array
    {
        $next = self::firstOf($year, $month)->addMonthNoOverflow();

        return self::isAfter($next->year, $next->month, $this->ceilingMonth())
            ? null
            : ['year' => $next->year, 'month' => $next->month];
    }

    public function atCeiling(int $year, int $month): bool
    {
        return ! self::isBefore($year, $month, $this->ceilingMonth());
    }

    public function atFloor(int $year, int $month): bool
    {
        return ! self::isAfter($year, $month, $this->floorMonth());
    }

    // The furthest day any grid in this window will draw, which is the last
    // cell of the ceiling month's strip and not the last day of that month.
    // Anything asking "does the reader have something ahead the calendar can
    // show" asks over this, or it answers about days nothing draws.
    public function lastDrawableDay(): CarbonImmutable
    {
        return CalendarGrid::endFor($this->ceilingMonth());
    }

    // The last day the projection states a balance for. The grid may not run
    // past it, so the ceiling is walked forward only while the WHOLE strip of
    // the next month still lands inside it.
    public function lastProjectedDay(): CarbonImmutable
    {
        return $this->clock->now()->startOfDay()->addDays(self::PROJECTION->value);
    }

    // Derived, never counted off in months: a fixed month count reaches past
    // the projection on all but one day of the year. The walk terminates
    // because each step moves at least 28 days against a fixed bound.
    public function ceilingMonth(): CarbonImmutable
    {
        $lastProjected = $this->lastProjectedDay();
        $ceiling = $this->clock->now()->startOfMonth()->startOfDay();

        $candidate = $ceiling->addMonthNoOverflow();
        while (CalendarGrid::endFor($candidate)->lessThanOrEqualTo($lastProjected)) {
            $ceiling = $candidate;
            $candidate = $candidate->addMonthNoOverflow();
        }

        return $ceiling;
    }

    // Stepped off the first of the month: a 29 February "now" stepped by whole
    // months overflows into March and moves the bound by one.
    private function floorMonth(): CarbonImmutable
    {
        return $this->clock->now()->startOfMonth()->subMonthsNoOverflow(self::HISTORY_MONTHS);
    }

    private static function firstOf(int $year, int $month): CarbonImmutable
    {
        return CarbonImmutable::parse(sprintf('%04d-%02d-01', $year, $month));
    }

    private static function isAfter(int $year, int $month, CarbonImmutable $bound): bool
    {
        return ($year > $bound->year)
            || ($year === $bound->year && $month > $bound->month);
    }

    private static function isBefore(int $year, int $month, CarbonImmutable $bound): bool
    {
        return ($year < $bound->year)
            || ($year === $bound->year && $month < $bound->month);
    }
}
