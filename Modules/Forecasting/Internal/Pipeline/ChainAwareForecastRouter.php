<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

/**
 * Wave 3 chain-aware contribution router.
 *
 * Sits between `RangeProjector` (emits per-occurrence contributions in
 * their native account / currency) and `DailyFold` (combines
 * contributions per day into a running balance + spread). Rewrites the
 * `accountId` field on contributions whose underlying series is
 * chain-resolved to a funder account, and SYNTHESISES a new
 * contribution for the upcoming ICS bulk-iDEAL settlement on the
 * funder's debit date.
 *
 * Algorithm (D-1002):
 *
 *   1. For each contribution, look up `ChainLinkQuery::confirmedAndDeterministicForSeries`.
 *      When the series has at least one confirmed or deterministic
 *      chain link, the contribution's `accountId` is rewritten to the
 *      funder account id. The point/low/high values are preserved —
 *      the funder is the one whose balance dips.
 *   2. Regardless of per-series chain links, synthesise the next ICS
 *      bulk-iDEAL settlement contribution on the ASN funder account
 *      via `CardStatementQuery::nextSettlementForUser`. The synthesised
 *      contribution lands on the funder account id the DTO already
 *      carries (resolved by Plan 10-02 Task 4 via the most-recent
 *      confirmed `ics_bulk_settle` chain_link).
 *   3. De-duplicate (funder account id, date) tuples that overlap
 *      between a Phase 8 recurring series and the synthesised ICS
 *      settlement — prefer the synthesised contribution as the
 *      authoritative source (the Phase 5 next-settlement math is the
 *      one ground truth).
 *   4. When `$viewByFunder=true`, the router collapses per-series
 *      contributions onto a single per-day-per-account aggregate so the
 *      downstream chart shows ONE line per funder account instead of
 *      N series-tagged lines. The aggregation runs after steps 1–3,
 *      so chain-resolved series and the synthesised ICS settlement are
 *      already on the funder account by the time collapse begins.
 *
 * FX conversion does NOT happen here (RESEARCH Pitfall 6). Each
 * contribution is left in its original currency; `DailyFold` applies
 * the per-contribution `fxRateUsed` to convert to the account's
 * default currency at fold time.
 *
 * Pure routing — no DB writes. Reads cross-module Public services
 * (`ChainLinkQuery` + `CardStatementQuery`) so the
 * `crossModuleAccessGoesThroughPublic` arch invariant stays green.
 */
final readonly class ChainAwareForecastRouter
{
    public function __construct(
        private ChainLinkQuery $chainQuery,
        private CardStatementQuery $cardStatementQuery,
        private Clock $clock,
    ) {}

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    public function route(array $contributions, User $user, bool $viewByFunder = false): array
    {
        // Step 1 — rewrite per-occurrence contributions onto the funder
        // account when the series has a confirmed-or-deterministic chain
        // link. Memoise the per-series lookup so a series with N
        // contributions only triggers one DB read.
        /** @var array<int, int|null> $funderBySeries — null marks "no chain". */
        $funderBySeries = [];

        $routed = [];
        foreach ($contributions as $contribution) {
            $funderAccountId = $this->resolveFunderForSeries(
                $contribution->seriesId,
                $user,
                $funderBySeries,
            );

            if ($funderAccountId === null || $funderAccountId === $contribution->accountId) {
                $routed[] = $contribution;

                continue;
            }

            $routed[] = new ForecastContribution(
                date: $contribution->date,
                pointMinor: $contribution->pointMinor,
                lowMinor: $contribution->lowMinor,
                highMinor: $contribution->highMinor,
                currency: $contribution->currency,
                fxRateUsed: $contribution->fxRateUsed,
                seriesId: $contribution->seriesId,
                accountId: $funderAccountId,
            );
        }

        // Step 2/3 — synthesise the next ICS bulk-iDEAL settlement onto
        // the ASN funder account (the DTO's `accountId` is the funder).
        $nextSettlement = $this->cardStatementQuery->nextSettlementForUser($user);
        if ($nextSettlement !== null) {
            $now = $this->clock->now()->startOfDay();
            $dueDate = CarbonImmutable::parse($nextSettlement->dueDate->toIso8601String())->startOfDay();

            // Drop on the floor when the settlement is in the past — the
            // projection horizon only extends forward.
            if ($dueDate->greaterThanOrEqualTo($now)) {
                $settlementMinor = -abs($nextSettlement->amount->toMinor());
                $synth = new ForecastContribution(
                    date: $dueDate,
                    pointMinor: $settlementMinor,
                    lowMinor: $settlementMinor,
                    highMinor: $settlementMinor,
                    currency: $nextSettlement->amount->currency(),
                    fxRateUsed: null,
                    seriesId: 0,
                    accountId: $nextSettlement->accountId,
                );

                // De-duplicate (funder account, date) overlaps with a
                // routed Phase 8 contribution. Prefer the synthesised
                // contribution — Phase 5 next-settlement math is the
                // authoritative source.
                $dedup = [];
                $dueKey = $synth->accountId.'|'.$dueDate->toDateString();
                foreach ($routed as $c) {
                    $cKey = $c->accountId.'|'.$c->date->toDateString();
                    if ($cKey === $dueKey) {
                        continue;
                    }
                    $dedup[] = $c;
                }
                $dedup[] = $synth;
                $routed = $dedup;
            }
        }

        if ($viewByFunder) {
            $routed = $this->collapseByFunder($routed);
        }

        return $routed;
    }

    /**
     * Collapse per-series contributions onto a single per-day-per-account
     * aggregate. The chain-resolution + ICS-bulk-iDEAL synthesis has
     * already routed each contribution onto its funder account by the
     * time this method runs; this aggregator merely sums every
     * contribution sharing a `(accountId, date)` tuple into one entry
     * per tuple so the downstream chart renders ONE line per account.
     *
     * Sign + currency / fxRateUsed handling: the collapse assumes
     * contributions sharing a (accountId, date) tuple are already in the
     * funder account's default currency by the time the daily fold
     * consumes them. Wave 5 does NOT inject currency conversion here —
     * that stays at the daily-fold boundary; this aggregator preserves
     * the currency of the first contribution at each tuple and sets
     * `fxRateUsed` and `seriesId` to null/0 to mark the entry as the
     * aggregate. The daily fold's quadrature math composes correctly
     * because the per-tuple sum is exactly what a per-funder chart
     * requires.
     *
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    private function collapseByFunder(array $contributions): array
    {
        /** @var array<string, array{date: CarbonImmutable, accountId: int, currency: string, point: int, low: int, high: int}> $buckets */
        $buckets = [];

        foreach ($contributions as $c) {
            $key = $c->accountId.'|'.$c->date->toDateString();
            if (! array_key_exists($key, $buckets)) {
                $buckets[$key] = [
                    'date' => $c->date,
                    'accountId' => $c->accountId,
                    'currency' => $c->currency,
                    'point' => 0,
                    'low' => 0,
                    'high' => 0,
                ];
            }
            $buckets[$key]['point'] += $c->pointMinor;
            $buckets[$key]['low'] += $c->lowMinor;
            $buckets[$key]['high'] += $c->highMinor;
        }

        $aggregated = [];
        foreach ($buckets as $b) {
            $aggregated[] = new ForecastContribution(
                date: $b['date'],
                pointMinor: $b['point'],
                lowMinor: $b['low'],
                highMinor: $b['high'],
                currency: $b['currency'],
                fxRateUsed: null,
                seriesId: 0,
                accountId: $b['accountId'],
            );
        }

        return $aggregated;
    }

    /**
     * @param  array<int, ?int>  $cache
     */
    private function resolveFunderForSeries(int $seriesId, User $user, array &$cache): ?int
    {
        if ($seriesId === 0) {
            return null;
        }

        if (array_key_exists($seriesId, $cache)) {
            return $cache[$seriesId];
        }

        $links = $this->chainQuery->confirmedAndDeterministicForSeries($seriesId, $user);
        if ($links === []) {
            $cache[$seriesId] = null;

            return null;
        }

        // Prefer the most-recently-confirmed link: the dataset is
        // already user-scoped + state/resolver-filtered; the first
        // returned row is the canonical funder.
        $cache[$seriesId] = $links[0]->funderAccountId;

        return $cache[$seriesId];
    }
}
