<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\SeriesConfidenceDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Public read API over the latest `forecast_runs.result_json` payload
 * for one (user, scenario, horizon) tuple. The /forecast page calls
 * `forUser` per account-and-horizon combination it renders; no
 * synchronous projection happens inside the request lifecycle (the
 * `noSynchronousForecastingInRequestLifecycle` arch invariant blocks
 * the heavy `ProjectionPipeline` class from being imported here).
 *
 * Cross-user 404: a missing or cross-user account id raises a
 * `NotFoundHttpException` so the calling Livewire component returns
 * a 404 response. The check uses a raw user-scoped row count rather
 * than `Account::query()->firstOrFail()` to keep the DI graph
 * Eloquent-free at this surface — the Models class still backs the
 * write paths, but the read API resolves through DatabaseManager.
 *
 * "Computing" sentinel: when the latest run for the tuple is not yet
 * `complete` (pending / running / failed / missing), the DTO carries
 * `isComputing=true` and an empty `points` array. The chart panel
 * shows the "Updating…" caption in this state.
 *
 * Flat-line fallback: when the run is complete but the account has no
 * series of its own (e.g. a new account with no recurring activity),
 * the points array is hydrated to `horizonDays + 1` flat days at the
 * account's anchor balance — the calm projection of "nothing changes
 * between now and the horizon end".
 */
final readonly class ForecastQuery
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private ForecastDtoMapper $mapper,
        private RecurringSeriesQuery $seriesQuery,
    ) {}

    public function forUser(int $accountId, int $horizonDays, ?int $scenarioId, User $user): ForecastDto
    {
        $account = $this->db->connection()->table('accounts')
            ->where('id', $accountId)
            ->where('user_id', $user->id)
            ->first();
        if ($account === null) {
            throw new NotFoundHttpException('Account not found.');
        }
        /** @var stdClass $account */
        $accountName = self::toString($account->name ?? null);
        $defaultCurrency = self::toString($account->default_currency ?? null);
        if ($defaultCurrency === '') {
            $defaultCurrency = 'EUR';
        }

        $runQuery = $this->db->connection()->table('forecast_runs')
            ->where('user_id', $user->id)
            ->where('horizon_days', $horizonDays);
        if ($scenarioId === null) {
            $runQuery->whereNull('scenario_id');
        } else {
            $runQuery->where('scenario_id', $scenarioId);
        }
        $row = $runQuery->orderByDesc('id')->first();

        $asOf = $this->clock->now()->startOfDay();

        if ($row === null) {
            return $this->computingSentinel($accountId, $accountName, $defaultCurrency, $horizonDays, $scenarioId, $asOf);
        }
        /** @var stdClass $row */
        $status = self::toString($row->status ?? null);
        if ($status !== 'complete') {
            return $this->computingSentinel($accountId, $accountName, $defaultCurrency, $horizonDays, $scenarioId, $asOf);
        }

        $rawJson = self::toString($row->result_json ?? null);
        if ($rawJson === '') {
            return $this->computingSentinel($accountId, $accountName, $defaultCurrency, $horizonDays, $scenarioId, $asOf);
        }

        $decoded = json_decode($rawJson, associative: true);
        if (! is_array($decoded)) {
            return $this->computingSentinel($accountId, $accountName, $defaultCurrency, $horizonDays, $scenarioId, $asOf);
        }
        $accountsBlock = $decoded['accounts'] ?? null;
        if (! is_array($accountsBlock)) {
            $accountsBlock = [];
        }

        $accountResult = $accountsBlock[(string) $accountId] ?? $accountsBlock[$accountId] ?? null;
        if (! is_array($accountResult)) {
            // Run is complete but this account has no series → flat line at the anchor.
            return $this->flatLineFallback($accountId, $accountName, $defaultCurrency, $horizonDays, $scenarioId, $asOf, $user);
        }

        $runAsOf = isset($decoded['as_of']) && is_string($decoded['as_of']) && $decoded['as_of'] !== ''
            ? CarbonImmutable::parse($decoded['as_of'])
            : $asOf;

        $confidence = $this->resolveSeriesConfidenceForAccount($accountId, $user);

        return $this->mapper->mapForecast(
            accountResult: $accountResult,
            horizonDays: $horizonDays,
            scenarioId: $scenarioId,
            asOf: $runAsOf,
            isComputing: false,
            seriesConfidence: $confidence,
        );
    }

    /**
     * Compute one `SeriesConfidenceDto` per approved series that
     * contributes to the given account. The bucket thresholds are
     * locked at code level (UI-SPEC):
     *   - band_width / |point| <= 10%  → high
     *   - 10% < ratio          <= 25%  → medium
     *   - 25% < ratio                  → low
     *
     * The band width is derived from the series's
     * `variance_tolerance_percent`: a series with var=5% has a band of
     * 10% of the magnitude (low at -5%, high at +5% → width 10%); var
     * of 10% has a 20%-wide band; etc. This is the envelope-tier
     * formula. Percentile-tier series get an empirical band on the
     * chart but the legend still reads the configured variance
     * tolerance — the variance is the user-visible signal of how
     * confident the projection is on this series.
     *
     * @return list<SeriesConfidenceDto>
     */
    private function resolveSeriesConfidenceForAccount(int $accountId, User $user): array
    {
        $allSeries = $this->seriesQuery->allApprovedForUser($user);
        if ($allSeries === []) {
            return [];
        }

        $seriesIds = array_map(static fn ($s): int => $s->seriesId, $allSeries);
        $accountIdBySeriesId = $this->seriesQuery->accountIdsForSeriesIds($seriesIds, $user);

        $result = [];
        foreach ($allSeries as $series) {
            $seriesAccountId = $accountIdBySeriesId[$series->seriesId] ?? null;
            if ($seriesAccountId !== $accountId) {
                continue;
            }

            $point = $series->latestAmount->toMinor();
            if ($point === 0) {
                continue;
            }
            $tol = $series->varianceTolerancePercent;
            $bandRatio = (2 * $tol); // band_width / |point| in percent.

            $confidence = match (true) {
                $bandRatio <= 10 => 'high',
                $bandRatio <= 25 => 'medium',
                default => 'low',
            };

            $magnitude = abs($point);
            $bandWidthMinor = (int) round($magnitude * $bandRatio / 100);

            $displayName = $series->displayNameOverride !== null && $series->displayNameOverride !== ''
                ? $series->displayNameOverride
                : $series->detectedName;

            $result[] = new SeriesConfidenceDto(
                seriesId: $series->seriesId,
                seriesName: $displayName,
                confidence: $confidence,
                pointMinor: $point,
                bandWidthMinor: $bandWidthMinor,
                currency: $series->latestAmount->currency(),
            );
        }

        return $result;
    }

    private function computingSentinel(
        int $accountId,
        string $accountName,
        string $defaultCurrency,
        int $horizonDays,
        ?int $scenarioId,
        CarbonImmutable $asOf,
    ): ForecastDto {
        return new ForecastDto(
            accountId: $accountId,
            accountName: $accountName,
            defaultCurrency: $defaultCurrency,
            horizonDays: $horizonDays,
            scenarioId: $scenarioId,
            asOf: $asOf,
            todayBalanceMinor: 0,
            points: [],
            seriesConfidence: [],
            isComputing: true,
        );
    }

    private function flatLineFallback(
        int $accountId,
        string $accountName,
        string $defaultCurrency,
        int $horizonDays,
        ?int $scenarioId,
        CarbonImmutable $asOf,
        User $user,
    ): ForecastDto {
        $anchorMinor = $this->resolveAnchorFromTransactionsSum($accountId, $user->id);

        $points = [];
        for ($day = 0; $day <= $horizonDays; $day++) {
            $points[] = new ForecastPointDto(
                date: $asOf->addDays($day)->toDateString(),
                lowMinor: $anchorMinor,
                pointMinor: $anchorMinor,
                highMinor: $anchorMinor,
                currency: $defaultCurrency,
            );
        }

        return new ForecastDto(
            accountId: $accountId,
            accountName: $accountName,
            defaultCurrency: $defaultCurrency,
            horizonDays: $horizonDays,
            scenarioId: $scenarioId,
            asOf: $asOf,
            todayBalanceMinor: $anchorMinor,
            points: $points,
            seriesConfidence: [],
            isComputing: false,
        );
    }

    private function resolveAnchorFromTransactionsSum(int $accountId, int $userId): int
    {
        return (int) $this->db->connection()->table('transactions')
            ->where('user_id', $userId)
            ->where('account_id', $accountId)
            ->sum('amount_minor');
    }

    private static function toString(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        return is_scalar($value) ? (string) $value : '';
    }
}
