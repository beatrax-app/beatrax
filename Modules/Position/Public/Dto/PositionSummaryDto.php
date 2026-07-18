<?php

declare(strict_types=1);

namespace Modules\Position\Public\Dto;

use Modules\Budgets\Public\Dto\BudgetProgressRow;
use Modules\EmailScan\Public\Dto\EmailScanHealthTile;
use Modules\Ledger\Public\Dto\DashboardSummary;
use Modules\Ledger\Public\Dto\PerCurrencyTile;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Spatie\LaravelData\Data;

/**
 * The single composed "your current position" value object (D-30). Built by
 * `PositionQuery::forUser()` from other modules' existing Public seams —
 * NEVER from a raw cross-module SELECT. The position digest (this plan) is
 * its first consumer; the dashboard's adoption of this same DTO is a
 * deliberately separate, independently-revertible plan (18-18, D-30
 * guard 1).
 *
 * `summary` is byte-for-byte the same `DashboardSummary` value
 * `ThisPeriodAtAGlanceQuery::for()` would return for the same (user,
 * period) — the "the digest and the dashboard can never disagree" property
 * this DTO exists to guarantee, asserted executable in `PositionQueryTest`
 * and reused as 18-18's regression anchor.
 *
 * `tilesByCurrency` mirrors the dashboard's `default_currency_view ===
 * 'original'` toggle: null in EUR-only mode, the per-currency breakdown
 * otherwise. `emailScanHealth` is null when the user has no connected
 * inboxes (the same "absence = no tile" contract the dashboard already
 * uses).
 *
 * `upcoming` is the user's approved recurring series whose `nextExpectedAt`
 * falls inside the queried period window, soonest-first. `budgets` is the
 * current-period budget progress rows — `Modules\Budgets`' OWN current
 * period, not the `$period` argument (`BudgetProgressQuery::
 * forCurrentPeriod()` resolves its own period internally). `shortfallAhead`
 * is true when Forecasting has at least one active baseline shortfall
 * window in the next 30 days
 * (`ForecastHighlightsQuery::activeShortfallCountForUser()` > 0) — Position
 * never re-implements Forecasting's own shortfall detection, only reads its
 * Public count.
 *
 * D-21: a user with zero transactions, zero budgets, zero upcoming charges
 * and no shortfall still gets a fully-populated DTO (never null) —
 * "nothing notable" is itself a valid position.
 */
final class PositionSummaryDto extends Data
{
    /**
     * @param  ?array<int, PerCurrencyTile>  $tilesByCurrency
     * @param  array<int, RecurringSeriesDto>  $upcoming
     * @param  array<int, BudgetProgressRow>  $budgets
     */
    public function __construct(
        public readonly DashboardSummary $summary,
        public readonly ?array $tilesByCurrency,
        public readonly ?EmailScanHealthTile $emailScanHealth,
        public readonly array $upcoming,
        public readonly array $budgets,
        public readonly bool $shortfallAhead,
    ) {}
}
