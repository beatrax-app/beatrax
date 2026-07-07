<?php

declare(strict_types=1);

namespace Modules\Reports\Internal\Aggregation;

use InvalidArgumentException;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Dto\Period;
use Modules\Reports\Internal\Aggregation\Dto\NetWorthSeriesPoint;
use Modules\Reports\Public\Dto\ReportDefinition;
use Modules\Reports\Public\Dto\ReportResultDto;
use Modules\Reports\Public\Dto\ReportResultRow;

/**
 * The Reports module's central Public-facing aggregation entry point
 * (Req 2/3/4/5) — every consumer (builder, CSV export, saved/pinned
 * reports, later plans) calls `run()` and never talks to a dimension query
 * or `CurrencyModeApplier` collaborator directly.
 *
 * Dispatch shape: `net_worth` (dimension ignored, a time series) is split
 * from the three transaction metrics (spend/income/net) FIRST, then a
 * `match` on `$definition->dimension` picks the one Plan 04 grouped query
 * matching the requested dimension. `net` is NOT composed here from
 * separately-run spend/income totals — every Plan 04 dimension query
 * already implements 'net' natively as `SUM(settled_amount_minor)` over
 * `type IN ('expense','income')`, so passing `metric: 'net'` straight
 * through already nets income against spend per group with zero extra
 * composition logic (the canonical type-based definition locked in Plan 04,
 * 999.6-RESEARCH.md Pitfall 1).
 *
 * T-999.6-06/T-999.6-14: `accounts`/`categories`/`counterparties` filter
 * ids from a persisted `ReportDefinition` are passed straight into the
 * Plan 04 dimension queries' own `whereIn(...)` predicates, which sit
 * ALONGSIDE each query's existing `where('user_id', $user->id)` guard — a
 * foreign id can therefore never widen a result to another user's rows, it
 * can only ever narrow (or zero) the CALLING user's own already-scoped
 * result. No separate pre-validation query is needed; the ownership guard
 * is structural, mirroring the "foreign id -> empty result" convention
 * documented across this codebase's read models (999.6-PATTERNS.md "Cross-
 * user isolation guard"). `amountMin`/`amountMax`/`amountDirection` are NOT
 * yet wired into any query (deferred — see this plan's SUMMARY.md).
 */
final class ReportAggregator
{
    public function __construct(
        private readonly PeriodPresetResolver $periodPresetResolver,
        private readonly CategorySpendQuery $categorySpendQuery,
        private readonly CounterpartySpendQuery $counterpartySpendQuery,
        private readonly AccountSpendQuery $accountSpendQuery,
        private readonly TimeBucketSpendQuery $timeBucketSpendQuery,
        private readonly NetWorthSeriesQuery $netWorthSeriesQuery,
        private readonly CurrencyModeApplier $currencyModeApplier,
    ) {}

    public function run(User $user, ReportDefinition $definition): ReportResultDto
    {
        $period = $this->periodPresetResolver->resolve($definition->periodPreset, $definition->customFrom, $definition->customTo);

        return $definition->metric === 'net_worth'
            ? $this->buildNetWorthResult($user, $period, $definition)
            : $this->buildTransactionResult($user, $period, $definition);
    }

    // -------------------------------------------------------------------
    // Transaction metrics: spend / income / net
    // -------------------------------------------------------------------

    private function buildTransactionResult(User $user, Period $period, ReportDefinition $definition): ReportResultDto
    {
        $queryForCurrency = fn (string $currency): array => $this->dimensionRows($user, $period, $definition, $currency);

        return $this->currencyModeApplier->apply($user, $period, $definition->metric, $definition->currencyMode, $queryForCurrency);
    }

    /**
     * @return list<ReportResultRow>
     */
    private function dimensionRows(User $user, Period $period, ReportDefinition $definition, string $currency): array
    {
        return match ($definition->dimension) {
            'category' => $this->categorySpendQuery->forUserAndPeriod(
                $user,
                $period,
                $definition->metric,
                $currency,
                accountIds: $definition->accounts,
                categoryIds: $definition->categories,
                counterpartyIds: $definition->counterparties,
            ),
            'counterparty' => $this->counterpartySpendQuery->forUserAndPeriod(
                $user,
                $period,
                $definition->metric,
                $currency,
                accountIds: $definition->accounts,
                categoryIds: $definition->categories,
                counterpartyIds: $definition->counterparties,
            ),
            'account' => $this->accountSpendQuery->forUserAndPeriod(
                $user,
                $period,
                $definition->metric,
                $currency,
                accountIds: $definition->accounts,
                categoryIds: $definition->categories,
                counterpartyIds: $definition->counterparties,
            ),
            'time_bucket' => $this->timeBucketSpendQuery->forUserAndPeriod(
                $user,
                $period,
                $definition->metric,
                $currency,
                $definition->granularity,
                accountIds: $definition->accounts,
                categoryIds: $definition->categories,
                counterpartyIds: $definition->counterparties,
            ),
            default => throw new InvalidArgumentException("Unknown report dimension: {$definition->dimension}"),
        };
    }

    // -------------------------------------------------------------------
    // net_worth metric — time series, dimension ignored (Req 2/7)
    // -------------------------------------------------------------------

    private function buildNetWorthResult(User $user, Period $period, ReportDefinition $definition): ReportResultDto
    {
        $points = $this->netWorthSeriesQuery->forUser($user, $period, $definition->granularity);
        $rows = self::pointsToRows($points);

        $totalMinor = 0;
        $currency = $user->base_currency;
        $hasExcluded = false;
        $excludedTotal = 0;
        foreach ($points as $point) {
            // Net worth is a balance, not a flow: the DTO-level total is the
            // MOST RECENT sample point (the series' own last row), never a
            // sum-across-points (which would double-count every account's
            // balance once per bucket) — overwritten on every iteration so
            // the LAST point wins.
            $totalMinor = $point->totalMinor;
            $currency = $point->currency;
            if ($point->excludedCount > 0) {
                $hasExcluded = true;
                $excludedTotal += $point->excludedCount;
            }
        }

        return new ReportResultDto(
            rows: $rows,
            totalMinor: $totalMinor,
            currency: $currency,
            hasExcludedAccounts: $hasExcluded,
            accountsWithoutRate: $excludedTotal,
        );
    }

    /**
     * @param  list<NetWorthSeriesPoint>  $points
     * @return list<ReportResultRow>
     */
    private static function pointsToRows(array $points): array
    {
        $rows = [];
        foreach ($points as $point) {
            $rows[] = new ReportResultRow(
                groupKey: $point->date->toDateString(),
                groupLabel: $point->label,
                amountMinor: $point->totalMinor,
                currency: $point->currency,
            );
        }

        return $rows;
    }
}
