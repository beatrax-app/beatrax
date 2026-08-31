<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\JobRunStatus;
use Modules\Forecasting\Internal\Enums\ForecastPointSet;
use Modules\Forecasting\Internal\Enums\SeriesConfidence;
use Modules\Forecasting\Internal\Mapping\ForecastDtoMapper;
use Modules\Forecasting\Internal\Mapping\ForecastWindow;
use Modules\Forecasting\Public\Dto\ForecastDto;
use Modules\Forecasting\Public\Dto\ForecastPointDto;
use Modules\Forecasting\Public\Dto\SeriesConfidenceDto;
use Modules\Ledger\Public\Services\AccountBalanceQuery;
use Modules\Ledger\Public\Services\BaseCurrency;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use stdClass;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class ForecastQuery
{
    // The two non-terminal run states. 'complete' and 'failed' are answers;
    // these are the only ones the user is still waiting on.
    private const array IN_FLIGHT_STATUSES = [JobRunStatus::Pending->value, JobRunStatus::Running->value];

    use CoercesScalars;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private ForecastDtoMapper $mapper,
        private RecurringSeriesQuery $seriesQuery,
        private BaseCurrency $baseCurrency,
        private AccountBalanceQuery $balances,
    ) {}

    public function forUser(int $accountId, int $horizonDays, ?int $scenarioId, User $user, bool $viewByFunder = false): ForecastDto
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
            $defaultCurrency = $this->baseCurrency->code();
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

        $window = new ForecastWindow($horizonDays, $scenarioId, $this->clock->now()->startOfDay());

        $decoded = $this->decodeCompletedRun($row);
        if ($decoded === null) {
            // Only a run actually in flight may say "updating". A device that
            // never computed one has nothing pending, and claiming otherwise
            // left the calendar promising a projection that never arrived.
            return $this->isRunInFlight($row)
                ? $this->computingSentinel($accountId, $accountName, $defaultCurrency, $window)
                : $this->flatLineFallback($accountId, $accountName, $defaultCurrency, $window, $user, self::hasRunFailed($row));
        }

        $accountsBlock = is_array($decoded['accounts'] ?? null) ? $decoded['accounts'] : [];
        $accountResult = $accountsBlock[(string) $accountId] ?? $accountsBlock[$accountId] ?? null;
        if (! is_array($accountResult)) {
            return $this->flatLineFallback($accountId, $accountName, $defaultCurrency, $window, $user);
        }

        $runAsOf = isset($decoded['as_of']) && is_string($decoded['as_of']) && $decoded['as_of'] !== ''
            ? CarbonImmutable::parse($decoded['as_of'])
            : $window->asOf;

        $confidence = $this->resolveSeriesConfidenceForAccount($accountId, $user);

        return $this->mapper->mapForecast(
            accountResult: $accountResult,
            window: $window->openingOn($runAsOf),
            isComputing: false,
            seriesConfidence: $confidence,
            pointSet: ForecastPointSet::for($viewByFunder),
            // The run opens on the day it was computed, so one that predates
            // today draws days already spent under the word "today".
            isStale: $runAsOf->lessThan($window->asOf),
        );
    }

    // A run that ended `failed` wrote no points, and the flat line drawn in its
    // place is indistinguishable from a real projection with nothing in it. The
    // scenario chart drew that line forever while every retry re-crashed.
    private static function hasRunFailed(mixed $row): bool
    {
        return $row instanceof stdClass && self::toString($row->status ?? null) === JobRunStatus::Failed->value;
    }

    private function isRunInFlight(mixed $row): bool
    {
        if (! $row instanceof stdClass) {
            return false;
        }

        return in_array(self::toString($row->status ?? null), self::IN_FLIGHT_STATUSES, true);
    }

    /**
     * @return array<mixed, mixed>|null
     */
    private function decodeCompletedRun(mixed $row): ?array
    {
        if (! $row instanceof stdClass || self::toString($row->status ?? null) !== JobRunStatus::Complete->value) {
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
            $bandRatio = 2 * $series->varianceTolerancePercent;
            $magnitude = abs($point);
            $bandWidthMinor = (int) round($magnitude * $bandRatio / 100);

            $displayName = $series->displayNameOverride !== null && $series->displayNameOverride !== ''
                ? $series->displayNameOverride
                : $series->detectedName;

            $result[] = new SeriesConfidenceDto(
                seriesId: $series->seriesId,
                seriesName: $displayName,
                confidence: SeriesConfidence::forBandRatio($bandRatio),
                pointMinor: $point,
                monthlyEquivalentMinor: $series->monthlyEquivalent->toMinor(),
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
        ForecastWindow $window,
    ): ForecastDto {
        return new ForecastDto(
            accountId: $accountId,
            accountName: $accountName,
            defaultCurrency: $defaultCurrency,
            horizonDays: $window->horizonDays,
            scenarioId: $window->scenarioId,
            asOf: $window->asOf,
            todayBalanceMinor: 0,
            points: [],
            seriesConfidence: [],
            isComputing: true,
        );
    }

    /**
     * @return list<string>
     */
    private function bookedDatesAhead(int $accountId, User $user, CarbonImmutable $asOf, int $horizonDays): array
    {
        $rows = $this->db->connection()->table('transactions')
            ->where('user_id', $user->id)
            ->where('account_id', $accountId)
            ->whereNotNull('booked_at')
            ->where('posted_at', '>', $asOf->toDateString())
            ->where('posted_at', '<=', $asOf->addDays($horizonDays)->toDateString().' 23:59:59')
            ->distinct()
            ->orderBy('posted_at')
            ->pluck('posted_at');

        $dates = [];
        foreach ($rows as $value) {
            if (is_string($value) && $value !== '') {
                $dates[CarbonImmutable::parse($value)->toDateString()] = true;
            }
        }

        return array_keys($dates);
    }

    private function flatLineFallback(
        int $accountId,
        string $accountName,
        string $defaultCurrency,
        ForecastWindow $window,
        User $user,
        bool $runFailed = false,
    ): ForecastDto {
        $asOf = $window->asOf;
        $horizonDays = $window->horizonDays;
        $anchorMinor = $this->balances->currentBalanceAsOf($accountId, $user, $asOf)->in($defaultCurrency);

        // A booked row dated ahead of today is a certainty already in the
        // ledger, and a line ignoring it states the wrong balance for every day
        // after. Only the days one falls are queried, through the summation the
        // dashboard and reconcile use so the baseline cannot drift from theirs.
        $balanceOn = [];
        foreach ($this->bookedDatesAhead($accountId, $user, $asOf, $horizonDays) as $date) {
            $balanceOn[$date] = $this->balances
                ->currentBalanceAsOf($accountId, $user, CarbonImmutable::parse($date)->endOfDay())
                ->in($defaultCurrency);
        }

        $points = [];
        $runningMinor = $anchorMinor;
        for ($day = 0; $day <= $horizonDays; $day++) {
            $date = $asOf->addDays($day)->toDateString();
            $runningMinor = $balanceOn[$date] ?? $runningMinor;

            // low == point == high: what is already booked carries no
            // uncertainty, which is the whole reason it may be drawn at all
            // without a projection behind it.
            $points[] = new ForecastPointDto(
                date: $date,
                lowMinor: $runningMinor,
                pointMinor: $runningMinor,
                highMinor: $runningMinor,
                currency: $defaultCurrency,
            );
        }

        return new ForecastDto(
            accountId: $accountId,
            accountName: $accountName,
            defaultCurrency: $defaultCurrency,
            horizonDays: $horizonDays,
            scenarioId: $window->scenarioId,
            asOf: $asOf,
            todayBalanceMinor: $anchorMinor,
            points: $points,
            seriesConfidence: [],
            isComputing: false,
            runFailed: $runFailed,
        );
    }
}
