<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\StateMachines\ForecastRunStateMachine;
use Modules\Forecasting\Models\ForecastRun;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;
use Throwable;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ProjectionPipeline
{
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
    ) {}

    public function project(User $user, ?int $scenarioId, int $horizonDays): void
    {
        $asOf = $this->clock->now()->startOfDay();

        $run = $this->createPendingRun($user, $scenarioId, $horizonDays);
        $this->stateMachine->start($run);

        try {
            $result = $this->computeResult($user, $scenarioId, $asOf, $horizonDays);

            $encoded = json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($encoded === false) {
                throw new \RuntimeException('ProjectionPipeline: failed to encode result_json.');
            }

            $this->db->connection()->table('forecast_runs')
                ->where('id', $run->id)
                ->update(['result_json' => $encoded]);

            $this->stateMachine->complete($run);
        } catch (Throwable $e) {
            $this->stateMachine->fail($run);
            throw $e;
        }
    }

    private function createPendingRun(User $user, ?int $scenarioId, int $horizonDays): ForecastRun
    {
        $run = new ForecastRun;
        $run->user_id = $user->id;
        $run->scenario_id = $scenarioId;
        $run->horizon_days = $horizonDays;
        $run->status = 'pending';
        $run->save();

        return $run;
    }

    /**
     * @return array{as_of: string, horizon_days: int, accounts: array<int, array{account_id: int, account_name: string, default_currency: string, today_balance_minor: int, anchor_source: string, points: list<array{date: string, low_minor: int, point_minor: int, high_minor: int, currency: string}>}>}
     */
    private function computeResult(User $user, ?int $scenarioId, CarbonImmutable $asOf, int $horizonDays): array
    {
        $allSeries = $this->seriesQuery->allApprovedForUser($user);

        $seriesIds = array_map(static fn ($s): int => $s->seriesId, $allSeries);
        $accountIdBySeriesId = $seriesIds === []
            ? []
            : $this->seriesQuery->accountIdsForSeriesIds($seriesIds, $user);

        $accounts = $this->db->connection()
            ->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        // Collect ALL contributions across ALL series first so the
        // chain-aware router can rewrite an account-id-routed
        // contribution before the per-account daily fold buckets them.
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

        $routed = $this->router->route(
            contributions: $allContributions,
            user: $user,
            viewByFunder: false,
        );

        // Scenario-isolation boundary: ScenarioApplier folds the user's
        // mutations on top of the baseline contributions purely in
        // memory — it NEVER joins forecast_scenario_mutations onto the
        // transaction substrate (noScenarioMutationsJoinedToTransactionQueries).
        if ($scenarioId !== null) {
            $routed = $this->scenarioApplier->apply(
                baselineContributions: $routed,
                scenarioId: $scenarioId,
                user: $user,
                asOf: $asOf,
                horizonDays: $horizonDays,
            );
        }

        /** @var array<int, list<ForecastContribution>> $byAccount */
        $byAccount = [];
        foreach ($routed as $contrib) {
            $byAccount[$contrib->accountId] ??= [];
            $byAccount[$contrib->accountId][] = $contrib;
        }

        $accountsResult = [];

        foreach ($accounts as $account) {
            /** @var stdClass $account */
            $accountId = self::toInt($account->id);
            $anchor = $this->anchor->forAccount($accountId, $user);
            $defaultCurrency = self::toString($account->default_currency ?? null);
            if ($defaultCurrency === '') {
                $defaultCurrency = 'EUR';
            }

            $contributions = $byAccount[$accountId] ?? [];

            $folded = $this->fold->fold(
                openingBalanceMinor: $anchor->openingBalanceMinor,
                contributions: $contributions,
                asOf: $asOf,
                horizonDays: $horizonDays,
                defaultCurrency: $defaultCurrency,
            );

            $points = array_values($folded);

            $effectiveBuffer = isset($account->forecast_min_buffer_minor) && is_numeric($account->forecast_min_buffer_minor)
                ? (int) $account->forecast_min_buffer_minor
                : null;

            $this->shortfall->detect(
                dailyPoints: $points,
                accountId: $accountId,
                scenarioId: $scenarioId,
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
                'points' => $points,
            ];
        }

        return [
            'as_of' => $asOf->toDateString(),
            'horizon_days' => $horizonDays,
            'accounts' => $accountsResult,
        ];
    }

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
