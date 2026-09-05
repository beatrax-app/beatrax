<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Forecasting\Internal\Enums\ForecastPointSet;
use Modules\Forecasting\Internal\Exceptions\ForecastResultEncodingException;
use Modules\Forecasting\Internal\StateMachines\ForecastRunStateMachine;
use Modules\Forecasting\Internal\Support\BufferFloor;
use Modules\Forecasting\Models\ForecastRun;
use Modules\FX\Public\Services\CrossCurrencyTotal;
use Modules\Ledger\Public\Enums\AccountKind;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;
use Throwable;

final readonly class ProjectionPipeline
{
    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private BalanceAnchorResolver $anchor,
        private RangeProjector $projector,
        private DailyFold $fold,
        private ForecastRunStateMachine $stateMachine,
        private RecurringSeriesQuery $seriesQuery,
        private Clock $clock,
        private ChainAwareForecastRouter $router,
        private ShortfallDetector $shortfall,
        private ScenarioApplier $scenarioApplier,
        private BaseCurrency $baseCurrency,
        private BookedRowProjector $bookedRows,
        private CadenceJitter $jitter,
        private CrossCurrencyTotal $fx,
    ) {}

    public function project(User $user, ?int $scenarioId, int $horizonDays): void
    {
        $asOf = $this->clock->now()->startOfDay();

        $run = $this->createPendingRun($user, $scenarioId, $horizonDays);

        // start() inside the try, not above it: the row exists the moment
        // createPendingRun returns, and a throw from the transition itself left
        // it `pending` with fail() unreachable — a chart stuck on "updating"
        // that no later reader can tell from one still being computed.
        try {
            $this->stateMachine->start($run);
            $result = $this->computeResult($user, $scenarioId, $asOf, $horizonDays);

            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new ForecastResultEncodingException('ProjectionPipeline: failed to encode result_json.');
            }

            $this->db->connection()->table('forecast_runs')
                ->where('id', $run->id)
                ->update(['result_json' => $encoded]);

            $this->stateMachine->complete($run);
            $this->pruneSupersededRuns($run, $scenarioId, $horizonDays);
        } catch (Throwable $e) {
            $this->stateMachine->fail($run);
            throw $e;
        }
    }

    // Every reader takes the newest run for a (user, scenario, horizon) and no
    // foreign key points at an older one, so everything below this id is already
    // unreachable. Unpruned the table only grows — 1,305 rows holding 54.6 MB of
    // result_json in thirteen hours, which every backup then carries.
    private function pruneSupersededRuns(ForecastRun $run, ?int $scenarioId, int $horizonDays): void
    {
        $query = $this->db->connection()->table('forecast_runs')
            ->where('user_id', self::toInt($run->user_id))
            ->where('horizon_days', $horizonDays)
            ->where('id', '<', self::toInt($run->id));

        if ($scenarioId === null) {
            $query->whereNull('scenario_id');
        } else {
            $query->where('scenario_id', $scenarioId);
        }

        $query->delete();
    }

    private function createPendingRun(User $user, ?int $scenarioId, int $horizonDays): ForecastRun
    {
        $run = new ForecastRun;
        $run->user_id = $user->id;
        $run->scenario_id = $scenarioId;
        $run->horizon_days = $horizonDays;
        $run->status = JobRunStatus::Pending->value;
        $run->save();

        return $run;
    }

    /**
     * @return array{as_of: string, horizon_days: int, accounts: array<int, array{account_id: int, account_name: string, default_currency: string, today_balance_minor: int, anchor_source: string, unconverted_currencies: list<string>, points: list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>, points_by_funder: list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>}>}
     */
    private function computeResult(User $user, ?int $scenarioId, CarbonImmutable $asOf, int $horizonDays): array
    {
        $allSeries = $this->seriesQuery->allApprovedForUser($user);

        $seriesIds = array_map(static fn (RecurringSeriesDto $s): int => $s->seriesId, $allSeries);
        $accountIdBySeriesId = $seriesIds === []
            ? []
            : $this->seriesQuery->accountIdsForSeriesIds($seriesIds, $user);

        $accounts = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $currencyByAccountId = [];
        foreach ($accounts as $account) {
            /** @var stdClass $account */
            $currency = self::toString($account->default_currency ?? null);
            $currencyByAccountId[self::toInt($account->id)] = $currency !== '' ? $currency : $this->baseCurrency->code();
        }

        // Every series first, then route: the router rewrites which account a
        // contribution lands on, so bucketing before it would misfile them.
        $allContributions = [];
        foreach ($allSeries as $series) {
            $seriesAccountId = $accountIdBySeriesId[$series->seriesId] ?? null;
            if ($seriesAccountId === null) {
                continue;
            }
            $seriesContribs = $this->projector->project(
                series: $series,
                accountId: $seriesAccountId,
                asOf: $asOf,
                horizonDays: $horizonDays,
                user: $user,
            );
            foreach ($seriesContribs as $contrib) {
                $allContributions[] = $contrib;
            }
        }

        // Before the router, so a booked row supersedes the estimate of the
        // same series wherever that estimate was about to be routed to.
        $allContributions = $this->bookedRows->mergeInto(
            seriesContributions: $allContributions,
            user: $user,
            asOf: $asOf,
            horizonDays: $horizonDays,
            currencyByAccountId: $currencyByAccountId,
        );

        $routed = $this->router->route(
            contributions: $allContributions,
            user: $user,
        );

        // The isolation boundary: mutations fold onto the baseline in memory,
        // never as a join from forecast_scenario_mutations onto transactions.
        if ($scenarioId !== null) {
            $routed = $this->scenarioApplier->apply(
                baselineContributions: $routed,
                scenarioId: $scenarioId,
                user: $user,
                asOf: $asOf,
                horizonDays: $horizonDays,
            );
        }

        // Jitter runs last. Every stage above this line selects occurrences —
        // booked-row supersession, chain routing, each scenario mutation — and
        // a replica is one seventh of an occurrence, not an occurrence of its
        // own. Smearing earlier charged a series seven times over.
        $routed = $this->jitter->apply($routed, $asOf, $asOf->addDays($horizonDays));

        $byAccount = self::bucketByAccount($routed);
        $byFunderAccount = self::bucketByAccount($this->router->collapseByFunder($routed));

        $accountsResult = [];

        // Every contribution currency in the run, not this account's own: two
        // accounts can share a target currency while holding different
        // denominations, and a map memoised off the first would report the
        // second's as unpriceable.
        $contributionCurrencies = self::currenciesIn($routed);

        // One lookup per (currency, target): ratesTo() reads the whole
        // exchange_rates table per currency.
        $ratesByTargetCurrency = [];

        foreach ($accounts as $account) {
            /** @var stdClass $account */
            $accountId = self::toInt($account->id);
            $anchor = $this->anchor->forAccount($accountId, $user);
            $defaultCurrency = $currencyByAccountId[$accountId];

            $perSeries = $byAccount[$accountId] ?? [];
            $byFunder = $byFunderAccount[$accountId] ?? [];
            $rates = $ratesByTargetCurrency[$defaultCurrency]
                ??= $this->fx->ratesTo($contributionCurrencies, $defaultCurrency);

            $fold = $this->fold->fold(
                openingBalanceMinor: $anchor->openingBalanceMinor,
                contributions: $perSeries,
                asOf: $asOf,
                horizonDays: $horizonDays,
                defaultCurrency: $defaultCurrency,
                rates: $rates,
            );
            $funderFold = $this->fold->fold(
                openingBalanceMinor: $anchor->openingBalanceMinor,
                contributions: $byFunder,
                asOf: $asOf,
                horizonDays: $horizonDays,
                defaultCurrency: $defaultCurrency,
                rates: $rates,
            );
            $points = array_values($fold->points);

            $effectiveBuffer = BufferFloor::forKind(
                AccountKind::tryFrom(self::toString($account->kind ?? null)),
                isset($account->forecast_min_buffer_minor) && is_numeric($account->forecast_min_buffer_minor)
                    ? (int) $account->forecast_min_buffer_minor
                    : null,
            );

            // The funder curve carries the same point estimates, so it would
            // detect the same windows; only the band differs.
            $this->shortfall->detect(
                dailyPoints: $points,
                accountId: $accountId,
                scenarioId: $scenarioId,
                horizonDays: $horizonDays,
                effectiveBufferMinor: $effectiveBuffer,
                currency: $defaultCurrency,
                user: $user,
            );

            $accountsResult[$accountId] = [
                'account_id' => $accountId,
                'account_name' => self::toString($account->name ?? null),
                'default_currency' => $defaultCurrency,
                'today_balance_minor' => $anchor->openingBalanceMinor,
                'anchor_source' => $anchor->source,
                'unconverted_currencies' => $fold->unconvertedCurrencies,
                ForecastPointSet::PerSeries->value => $points,
                ForecastPointSet::ByFunder->value => array_values($funderFold->points),
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'horizon_days' => $horizonDays,
            'accounts' => $accountsResult,
        ];
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return array<int, list<ForecastContribution>>
     */
    private static function bucketByAccount(array $contributions): array
    {
        /** @var array<int, list<ForecastContribution>> $byAccount */
        $byAccount = [];
        foreach ($contributions as $contribution) {
            $byAccount[$contribution->accountId] ??= [];
            $byAccount[$contribution->accountId][] = $contribution;
        }

        return $byAccount;
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<string>
     */
    private static function currenciesIn(array $contributions): array
    {
        $codes = [];
        foreach ($contributions as $contribution) {
            $codes[$contribution->currency] = true;
        }

        return array_keys($codes);
    }
}
