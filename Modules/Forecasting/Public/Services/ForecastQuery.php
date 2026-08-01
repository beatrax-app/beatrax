<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\SeriesConfidenceDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ForecastQuery
{
    use CoercesScalars;

    // A series' forecast confidence follows its band ratio — the full band
    // width as a percentage of the point, i.e. twice the variance tolerance.
    // A band no wider than this reads as high confidence; up to the medium
    // bound, medium; anything wider, low.
    private const int HIGH_CONFIDENCE_MAX_BAND_RATIO = 10;

    private const int MEDIUM_CONFIDENCE_MAX_BAND_RATIO = 25;

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

        // A missing run, a not-yet-complete run, or an unparseable
        // result_json all resolve to the same "still computing" sentinel;
        // decodeCompletedRun returns null for every one of those cases.
        $decoded = $this->decodeCompletedRun($row);
        if ($decoded === null) {
            return $this->computingSentinel($accountId, $accountName, $defaultCurrency, $horizonDays, $scenarioId, $asOf);
        }

        $accountsBlock = is_array($decoded['accounts'] ?? null) ? $decoded['accounts'] : [];
        $accountResult = $accountsBlock[(string) $accountId] ?? $accountsBlock[$accountId] ?? null;
        if (! is_array($accountResult)) {
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
     * @return array<mixed, mixed>|null
     */
    private function decodeCompletedRun(mixed $row): ?array
    {
        if (! $row instanceof stdClass || self::toString($row->status ?? null) !== 'complete') {
            return null;
        }
        $rawJson = self::toString($row->result_json ?? null);
        if ($rawJson === '') {
            return null;
        }

        $decoded = json_decode($rawJson, associative: true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @link ../../../../.docs/features/forecasting/architecture.md
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
            $bandRatio = (2 * $tol);

            $confidence = match (true) {
                $bandRatio <= self::HIGH_CONFIDENCE_MAX_BAND_RATIO => 'high',
                $bandRatio <= self::MEDIUM_CONFIDENCE_MAX_BAND_RATIO => 'medium',
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
}
