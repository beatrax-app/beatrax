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
 * Orchestrates the Wave 2 baseline-only projection.
 *
 * Composes `BalanceAnchorResolver` (per-account opening balance),
 * `RangeProjector` (per-occurrence envelope contributions), and
 * `DailyFold` (signed running balance + quadrature spread per day)
 * into a single per-(user, scenarioId, horizon) projection.
 *
 * Output shape — serialized to `forecast_runs.result_json`:
 *
 *   {
 *     "as_of": "YYYY-MM-DD",
 *     "horizon_days": 30,
 *     "accounts": {
 *       "<accountId>": {
 *         "account_id": 1,
 *         "account_name": "ASN Betaalrekening",
 *         "default_currency": "EUR",
 *         "today_balance_minor": 150000,
 *         "anchor_source": "user_input_opening_balance",
 *         "points": [{date, low_minor, point_minor, high_minor, currency}, ...]
 *       }
 *     }
 *   }
 *
 * Lifecycle is mediated by `ForecastRunStateMachine`: the pipeline
 * creates a `pending` row, transitions to `running`, writes the
 * `result_json`, and transitions to `complete`. Any thrown exception
 * inside the heavy work transitions the run to `failed` and re-throws
 * so the queue worker logs the stack trace.
 *
 * Wave 2 deliberately ignores scenarios (the `$scenarioId` parameter is
 * always null at this wave). Wave 4 introduces `ScenarioApplier` ahead
 * of `RangeProjector` to fold in the user's saved mutations before the
 * per-occurrence emission.
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
    ) {}

    public function project(User $user, ?int $scenarioId, int $horizonDays): void
    {
        $asOf = $this->clock->now()->startOfDay();

        $run = $this->createPendingRun($user, $scenarioId, $horizonDays);
        $this->stateMachine->start($run);

        try {
            $result = $this->computeResult($user, $asOf, $horizonDays);

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
    private function computeResult(User $user, CarbonImmutable $asOf, int $horizonDays): array
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

        $accountsResult = [];

        foreach ($accounts as $account) {
            /** @var stdClass $account */
            $accountId = self::toInt($account->id);
            $anchor = $this->anchor->forAccount($accountId, $user);
            $defaultCurrency = self::toString($account->default_currency ?? null);
            if ($defaultCurrency === '') {
                $defaultCurrency = 'EUR';
            }

            // Collect per-series contributions for this account.
            $contributions = [];
            foreach ($allSeries as $series) {
                $seriesAccountId = $accountIdBySeriesId[$series->seriesId] ?? null;
                if ($seriesAccountId !== $accountId) {
                    continue;
                }
                $seriesContribs = $this->projector->envelope(
                    series: $series,
                    accountId: $accountId,
                    asOf: $asOf,
                    horizonDays: $horizonDays,
                    user: $user,
                );
                foreach ($seriesContribs as $contrib) {
                    $contributions[] = $contrib;
                }
            }

            $folded = $this->fold->fold(
                openingBalanceMinor: $anchor->openingBalanceMinor,
                contributions: $contributions,
                asOf: $asOf,
                horizonDays: $horizonDays,
                defaultCurrency: $defaultCurrency,
            );

            $points = array_values($folded);

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
