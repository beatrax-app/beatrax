<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;

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
        // Memoised so a series with N contributions costs one DB read.
        /** @var array<int, int|null> $funderBySeries — null marks "no chain". */
        $funderBySeries = [];

        $routed = [];
        /** @var array<int, true> $chainRoutedSeriesIds */
        $chainRoutedSeriesIds = [];
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

            $chainRoutedSeriesIds[$contribution->seriesId] = true;
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

        // The settlement DTO's accountId is the funder, not the card account.
        $routed = $this->appendSettlement($routed, $user, $chainRoutedSeriesIds);

        if ($viewByFunder) {
            $routed = $this->collapseByFunder($routed);
        }

        return $routed;
    }

    /**
     * @param  list<ForecastContribution>  $routed
     * @param  array<int, true>  $chainRoutedSeriesIds
     * @return list<ForecastContribution>
     */
    private function appendSettlement(array $routed, User $user, array $chainRoutedSeriesIds): array
    {
        $nextSettlement = $this->cardStatementQuery->nextSettlementForUser($user);
        if ($nextSettlement === null) {
            return $routed;
        }

        $now = $this->clock->now()->startOfDay();
        $dueDate = CarbonImmutable::parse($nextSettlement->dueDate->toIso8601String())->startOfDay();

        // The projection horizon only extends forward.
        if ($dueDate->lessThan($now)) {
            return $routed;
        }

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

        return $this->dedupForSettlement($routed, $synth, $chainRoutedSeriesIds);
    }

    /**
     * @param  list<ForecastContribution>  $routed
     * @param  array<int, true>  $chainRoutedSeriesIds
     * @return list<ForecastContribution>
     */
    private function dedupForSettlement(array $routed, ForecastContribution $synth, array $chainRoutedSeriesIds): array
    {
        // Scoped to the chain-routed ids so an unchained series that merely
        // happens to land on the settlement date is not deduplicated away.
        $dueKey = $synth->accountId.'|'.$synth->date->toDateString();
        $dedup = [];
        foreach ($routed as $c) {
            $cKey = $c->accountId.'|'.$c->date->toDateString();
            if ($cKey === $dueKey && $c->seriesId !== 0 && array_key_exists($c->seriesId, $chainRoutedSeriesIds)) {
                continue;
            }
            $dedup[] = $c;
        }
        $dedup[] = $synth;

        return $dedup;
    }

    // Assumes contributions sharing an (accountId, date) tuple are already in
    // the funder's default currency; conversion stays at the daily-fold.
    /**
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

        // The query is already user-scoped and state-filtered, so the first row
        // is the canonical funder.
        $cache[$seriesId] = $links === [] ? null : $links[0]->funderAccountId;

        return $cache[$seriesId];
    }
}
