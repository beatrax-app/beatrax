<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Chains\Public\Services\CardStatementQuery;
use Modules\Chains\Public\Services\ChainLinkQuery;
use Modules\Chains\Public\Support\SettlementTolerance;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Public\Support\MatchWindow;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md#chain-aware-routing
 */
final readonly class ChainAwareForecastRouter
{
    private const int NO_SERIES = 0;

    public function __construct(
        private ChainLinkQuery $chainQuery,
        private CardStatementQuery $cardStatementQuery,
        private Clock $clock,
    ) {}

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    public function route(array $contributions, User $user): array
    {
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
                seriesId: $contribution->seriesId,
                accountId: $funderAccountId,
                dateIsUncertain: $contribution->dateIsUncertain,
            );
        }

        return $this->appendSettlement($routed, $user, $chainRoutedSeriesIds);
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
            seriesId: self::NO_SERIES,
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
            if ($cKey === $dueKey && $c->seriesId !== self::NO_SERIES && array_key_exists($c->seriesId, $chainRoutedSeriesIds)) {
                continue;
            }
            $dedup[] = $c;
        }

        if ($this->bookedAlready($dedup, $synth)) {
            return $dedup;
        }

        $dedup[] = $synth;

        return $dedup;
    }

    // The ledger already holds the debit this settlement infers, so the
    // inference is dropped and the booked row carries the day on its own.
    /**
     * @param  list<ForecastContribution>  $contributions
     */
    private function bookedAlready(array $contributions, ForecastContribution $synth): bool
    {
        $tolerance = SettlementTolerance::minorFor($synth->pointMinor);

        foreach ($contributions as $c) {
            if ($c->seriesId !== self::NO_SERIES || $c->accountId !== $synth->accountId || $c->currency !== $synth->currency) {
                continue;
            }
            if (abs($c->date->diffInDays($synth->date)) > MatchWindow::DAYS) {
                continue;
            }
            if (abs($c->pointMinor - $synth->pointMinor) <= $tolerance) {
                return true;
            }
        }

        return false;
    }

    // Bucketed by currency as well as by (accountId, date). This router never
    // converts — that stays at the daily fold — so summing two denominations
    // under the first one's code would state a total in a currency the money
    // was never in.
    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    public function collapseByFunder(array $contributions): array
    {
        /** @var array<string, array{date: CarbonImmutable, accountId: int, currency: string, point: int, low: int, high: int}> $buckets */
        $buckets = [];

        foreach ($contributions as $c) {
            $key = implode('|', [
                (string) $c->accountId,
                $c->date->toDateString(),
                $c->currency,
            ]);
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
                seriesId: self::NO_SERIES,
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
        if ($seriesId === self::NO_SERIES) {
            return null;
        }

        if (array_key_exists($seriesId, $cache)) {
            return $cache[$seriesId];
        }

        $links = $this->chainQuery->confirmedFundersForSeries($seriesId, $user);

        // The query orders by confidence then id, so the first row is the same
        // funder on every run — unordered, two equally confirmed funders took
        // turns and the projection moved between accounts for no reason.
        $cache[$seriesId] = $links === [] ? null : $links[0]->funderAccountId;

        return $cache[$seriesId];
    }
}
