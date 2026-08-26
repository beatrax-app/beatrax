<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\SafeDate;
use Modules\Forecasting\Public\Dto\ScenarioMutationDto;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ScenarioMutationKind;
use Modules\Forecasting\Public\Enums\ShiftScope;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Ledger\Public\Enums\Direction;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;

final readonly class ScenarioApplier
{
    // The add_recurring form has no variance-tolerance field, so its band is a
    // fixed ±5% rather than a series-derived width.
    private const float ONE_OFF_ENVELOPE_LOW_MULTIPLIER = 0.95;

    private const float ONE_OFF_ENVELOPE_HIGH_MULTIPLIER = 1.05;

    public function __construct(
        private ScenarioQuery $scenarioQuery,
        private RecurringSeriesQuery $seriesQuery,
        private CadenceWalk $walk,
        private DatabaseManager $db,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param  list<ForecastContribution>  $baselineContributions
     * @return list<ForecastContribution>
     */
    public function apply(
        array $baselineContributions,
        int $scenarioId,
        User $user,
        CarbonImmutable $asOf,
        int $horizonDays,
    ): array {
        $mutations = $this->scenarioQuery->mutationsFor($scenarioId, $user);
        if ($mutations === []) {
            return $baselineContributions;
        }

        $contributions = $baselineContributions;
        $horizonEnd = $asOf->addDays($horizonDays);

        foreach ($mutations as $mutation) {
            $contributions = $this->applyOne($contributions, $mutation, $user, $asOf, $horizonEnd, $horizonDays);
        }

        return $contributions;
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    private function applyOne(
        array $contributions,
        ScenarioMutationDto $mutation,
        User $user,
        CarbonImmutable $asOf,
        CarbonImmutable $horizonEnd,
        int $horizonDays,
    ): array {
        unset($horizonDays);

        // The default arm is unreachable — the typed cast raises on an unknown
        // kind at read time — so it no-ops rather than guessing.
        return match (true) {
            $mutation->payload instanceof CancelSeriesPayload => $this->applyCancelSeries($contributions, $mutation->payload),
            $mutation->payload instanceof AddOneOffPayload => $this->applyAddOneOff($contributions, $mutation->payload, $asOf, $horizonEnd, $user),
            $mutation->payload instanceof AddRecurringPayload => $this->applyAddRecurring($contributions, $mutation->payload, $asOf, $horizonEnd, $user),
            $mutation->payload instanceof ChangeSeriesAmountPayload => $this->applyChangeSeriesAmount($contributions, $mutation->payload, $user),
            $mutation->payload instanceof ShiftSeriesDatePayload => $this->applyShiftSeriesDate($contributions, $mutation->payload, $horizonEnd),
            default => $contributions,
        };
    }

    // An index rather than a date: a ShiftScope::Next shift moves exactly that
    // one entry, so the caller has to identify it positionally.
    /**
     * @param  list<ForecastContribution>  $contributions
     */
    private function earliestIndexForSeries(array $contributions, int $seriesId): ?int
    {
        $earliestIndex = null;
        foreach ($contributions as $i => $c) {
            if ($c->seriesId !== $seriesId) {
                continue;
            }
            if ($earliestIndex === null || $c->date->lessThan($contributions[$earliestIndex]->date)) {
                $earliestIndex = $i;
            }
        }

        return $earliestIndex;
    }

    // The bounded parse. A recurring start may precede asOf — the occurrence
    // walk skips those — so the two callers that read a start date go straight
    // to SafeDate and keep no horizon of their own.
    private function dateWithinHorizon(string $raw, CarbonImmutable $asOf, CarbonImmutable $horizonEnd): ?CarbonImmutable
    {
        $date = SafeDate::parseDayOrNull($raw);

        return $date === null || $date->lessThan($asOf) || $date->greaterThan($horizonEnd) ? null : $date;
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    private function applyCancelSeries(array $contributions, CancelSeriesPayload $payload): array
    {
        $result = [];
        foreach ($contributions as $c) {
            if ($c->seriesId === $payload->seriesId) {
                continue;
            }
            $result[] = $c;
        }

        return $result;
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    private function applyAddOneOff(
        array $contributions,
        AddOneOffPayload $payload,
        CarbonImmutable $asOf,
        CarbonImmutable $horizonEnd,
        User $user,
    ): array {
        $date = $this->dateWithinHorizon($payload->date, $asOf, $horizonEnd);

        $accountId = $date === null ? 0 : $this->pickAccountIdForOneOff($contributions, $user);

        if ($date === null || $accountId === 0) {
            return $contributions;
        }

        $signed = ($payload->direction === Direction::Income->value ? 1 : -1) * abs($payload->amountMinor);

        $contributions[] = new ForecastContribution(
            date: $date,
            pointMinor: $signed,
            lowMinor: $signed,
            highMinor: $signed,
            currency: $payload->currency,
            fxRateUsed: null,
            seriesId: 0,
            accountId: $accountId,
        );

        return $contributions;
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     */
    private function pickAccountIdForOneOff(array $contributions, User $user): int
    {
        $counts = [];
        foreach ($contributions as $c) {
            $counts[$c->accountId] = ($counts[$c->accountId] ?? 0) + 1;
        }
        if ($counts !== []) {
            uksort($counts, static function (int $a, int $b) use ($counts): int {
                $byCount = $counts[$b] <=> $counts[$a];
                if ($byCount !== 0) {
                    return $byCount;
                }

                return $a <=> $b;
            });

            return array_key_first($counts);
        }

        $accountId = $this->db->connection()->table('accounts')
            ->where('user_id', $user->id)
            ->orderBy('id')
            ->value('id');
        if (is_numeric($accountId) && (int) $accountId > 0) {
            return (int) $accountId;
        }

        $this->logger->warning(
            'ScenarioApplier: cannot place a one-off scenario mutation — empty baseline AND user owns no accounts (mutation skipped)',
            ['user_id' => $user->id],
        );

        return 0;
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    private function applyAddRecurring(
        array $contributions,
        AddRecurringPayload $payload,
        CarbonImmutable $asOf,
        CarbonImmutable $horizonEnd,
        User $user,
    ): array {
        $start = SafeDate::parseDayOrNull($payload->startDate);
        $cadence = SeriesCadence::tryFrom($payload->cadence);

        if ($start === null || $cadence === null || ! $cadence->isRegular()) {
            return $contributions;
        }

        $accountId = $this->pickAccountIdForOneOff($contributions, $user);

        if ($accountId === 0) {
            return $contributions;
        }

        $magnitude = abs($payload->amountMinor);
        $sign = $payload->direction === Direction::Income->value ? 1 : -1;
        $point = $sign * $magnitude;
        $lowMag = (int) round($magnitude * self::ONE_OFF_ENVELOPE_LOW_MULTIPLIER);
        $highMag = (int) round($magnitude * self::ONE_OFF_ENVELOPE_HIGH_MULTIPLIER);
        [$lowMinor, $highMinor] = $sign < 0 ? [-$highMag, -$lowMag] : [$lowMag, $highMag];

        foreach ($this->walk->datesInHorizon($start, $cadence, $asOf, $horizonEnd) as $date) {
            $contributions[] = new ForecastContribution(
                date: $date,
                pointMinor: $point,
                lowMinor: $lowMinor,
                highMinor: $highMinor,
                currency: $payload->currency,
                fxRateUsed: null,
                seriesId: 0,
                accountId: $accountId,
            );
        }

        return $contributions;
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    private function applyChangeSeriesAmount(array $contributions, ChangeSeriesAmountPayload $payload, User $user): array
    {
        $series = $this->seriesQuery->forSeries($payload->seriesId, $user);
        if ($series === null) {
            $this->logCrossUserMismatchIfAny(ScenarioMutationKind::ChangeSeriesAmount->value, $payload->seriesId, $user);

            return $contributions;
        }
        $tol = $series->varianceTolerancePercent;

        $result = [];
        foreach ($contributions as $c) {
            if ($c->seriesId !== $payload->seriesId) {
                $result[] = $c;

                continue;
            }
            $result[] = $this->rewriteAmount($c, $payload->newAmountMinor, $tol, $series);
        }

        return $result;
    }

    private function rewriteAmount(
        ForecastContribution $c,
        int $newAmountMinor,
        int $tol,
        RecurringSeriesDto $series,
    ): ForecastContribution {
        // The form collects a magnitude, so the sign comes from the series:
        // a positive entry against an expense stays an expense.
        $magnitude = abs($newAmountMinor);
        $originalPoint = $series->latestAmount->toMinor();
        $sign = $originalPoint < 0 ? -1 : 1;
        $signedPoint = $sign * $magnitude;
        $lowMag = (int) round($magnitude * (100 - $tol) / 100);
        $highMag = (int) round($magnitude * (100 + $tol) / 100);
        if ($sign < 0) {
            $lowMinor = -$highMag;
            $highMinor = -$lowMag;
        } else {
            $lowMinor = $lowMag;
            $highMinor = $highMag;
        }

        return new ForecastContribution(
            date: $c->date,
            pointMinor: $signedPoint,
            lowMinor: $lowMinor,
            highMinor: $highMinor,
            currency: $c->currency,
            fxRateUsed: $c->fxRateUsed,
            seriesId: $c->seriesId,
            accountId: $c->accountId,
        );
    }

    /**
     * @param  list<ForecastContribution>  $contributions
     * @return list<ForecastContribution>
     */
    private function applyShiftSeriesDate(
        array $contributions,
        ShiftSeriesDatePayload $payload,
        CarbonImmutable $horizonEnd,
    ): array {
        $newDate = SafeDate::parseDayOrNull($payload->newNextDate);
        $firstIndex = $this->earliestIndexForSeries($contributions, $payload->seriesId);
        $firstDate = $firstIndex === null ? null : $contributions[$firstIndex]->date;

        $deltaDays = $newDate === null || $firstDate === null
            ? 0
            : (int) round($firstDate->diffInDays($newDate, false));

        if ($deltaDays === 0) {
            return $contributions;
        }

        $shiftAll = $payload->scope === ShiftScope::AllSubsequent->value;

        $result = [];
        foreach ($contributions as $i => $c) {
            if ($c->seriesId !== $payload->seriesId) {
                $result[] = $c;

                continue;
            }
            $shouldShift = $shiftAll || $i === $firstIndex;
            if (! $shouldShift) {
                $result[] = $c;

                continue;
            }
            $shifted = $c->date->addDays($deltaDays);
            if ($shifted->greaterThan($horizonEnd)) {
                continue;
            }
            $result[] = new ForecastContribution(
                date: $shifted,
                pointMinor: $c->pointMinor,
                lowMinor: $c->lowMinor,
                highMinor: $c->highMinor,
                currency: $c->currency,
                fxRateUsed: $c->fxRateUsed,
                seriesId: $c->seriesId,
                accountId: $c->accountId,
            );
        }

        return $result;
    }

    private function logCrossUserMismatchIfAny(string $mutationKind, int $seriesId, User $user): void
    {
        $exists = $this->db->connection()->table('recurring_series')
            ->where('id', $seriesId)
            ->exists();
        if (! $exists) {
            return;
        }

        $this->logger->warning(
            'ScenarioApplier: cross-user series reference detected (mutation skipped)',
            [
                'mutation_kind' => $mutationKind,
                'series_id' => $seriesId,
                'user_id' => $user->id,
            ],
        );
    }
}
