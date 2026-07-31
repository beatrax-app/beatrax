<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Forecasting\Public\Dto\ScenarioMutationDto;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Enums\ShiftScope;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/forecasting/architecture.md
 */
final readonly class ScenarioApplier
{
    public function __construct(
        private ScenarioQuery $scenarioQuery,
        private RecurringSeriesQuery $seriesQuery,
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
        // $horizonDays is accepted for symmetry with the per-kind helper
        // signatures; no kind needs it.
        unset($horizonDays);

        // The default arm is unreachable in practice — the typed cast already
        // raises on an unknown kind at read time — and leaves the
        // contributions untouched rather than guessing at one.
        return match (true) {
            $mutation->payload instanceof CancelSeriesPayload => $this->applyCancelSeries($contributions, $mutation->payload),
            $mutation->payload instanceof AddOneOffPayload => $this->applyAddOneOff($contributions, $mutation->payload, $asOf, $horizonEnd, $user),
            $mutation->payload instanceof AddRecurringPayload => $this->applyAddRecurring($contributions, $mutation->payload, $asOf, $horizonEnd, $user),
            $mutation->payload instanceof ChangeSeriesAmountPayload => $this->applyChangeSeriesAmount($contributions, $mutation->payload, $user),
            $mutation->payload instanceof ShiftSeriesDatePayload => $this->applyShiftSeriesDate($contributions, $mutation->payload, $horizonEnd),
            default => $contributions,
        };
    }

    // The index of the earliest contribution for a series, or null when the
    // baseline holds none — a shift with nothing to shift. The index rather
    // than the date because a 'first_only' scope shifts exactly that entry.
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

    // A payload date parsed to a day boundary, or null when it will not parse.
    // The recurring start is allowed to precede asOf — the occurrence walk
    // skips those — so it is bounded by the caller rather than here.
    private function parsedDate(string $raw): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($raw)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    // A payload date parsed and bounded to the projection window, or null when
    // it is unparseable or falls outside it — both meaning the mutation has
    // nothing to contribute rather than that something went wrong.
    private function dateWithinHorizon(string $raw, CarbonImmutable $asOf, CarbonImmutable $horizonEnd): ?CarbonImmutable
    {
        $date = $this->parsedDate($raw);

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

        // pickAccountIdForOneOff answers 0 when there is no account to land on
        // — an empty baseline AND no owned account — and has already logged.
        $accountId = $date === null ? 0 : $this->pickAccountIdForOneOff($contributions, $user);

        if ($date === null || $accountId === 0) {
            return $contributions;
        }

        $signed = ($payload->direction === 'income' ? 1 : -1) * abs($payload->amountMinor);

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
     * @link ../../../../.docs/features/forecasting/architecture.md
     *
     * @param  list<ForecastContribution>  $contributions
     */
    private function pickAccountIdForOneOff(array $contributions, User $user): int
    {
        $counts = [];
        foreach ($contributions as $c) {
            $counts[$c->accountId] = ($counts[$c->accountId] ?? 0) + 1;
        }
        if ($counts !== []) {
            // Sort by count DESC, then by accountId ASC for a
            // deterministic tie-break.
            uksort($counts, static function (int $a, int $b) use ($counts): int {
                $byCount = $counts[$b] <=> $counts[$a];
                if ($byCount !== 0) {
                    return $byCount;
                }

                return $a <=> $b;
            });

            return array_key_first($counts);
        }

        // Empty baseline — pick the user's lowest-id account as the
        // landing pad.
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
        $start = $this->parsedDate($payload->startDate);
        $cadence = $payload->cadence;

        // pickAccountIdForOneOff is only asked once the payload is known to be
        // usable, so an unusable one never reaches its logging.
        $usable = $start !== null && in_array($cadence, ['weekly', 'monthly', 'quarterly', 'yearly'], true);
        $accountId = $usable ? $this->pickAccountIdForOneOff($contributions, $user) : 0;

        if ($start === null || ! $usable || $accountId === 0) {
            return $contributions;
        }

        $magnitude = abs($payload->amountMinor);
        $sign = $payload->direction === 'income' ? 1 : -1;
        $point = $sign * $magnitude;
        // ±5% calmest-default envelope — the add_recurring form has no
        // variance-tolerance field, so a fixed conservative band is used.
        $low5 = (int) round($magnitude * 0.95);
        $high5 = (int) round($magnitude * 1.05);
        [$lowMinor, $highMinor] = $sign < 0 ? [-$high5, -$low5] : [$low5, $high5];

        $cursor = $start;
        while ($cursor->lessThanOrEqualTo($horizonEnd)) {
            if ($cursor->greaterThanOrEqualTo($asOf)) {
                $contributions[] = new ForecastContribution(
                    date: $cursor,
                    pointMinor: $point,
                    lowMinor: $lowMinor,
                    highMinor: $highMinor,
                    currency: $payload->currency,
                    fxRateUsed: null,
                    seriesId: 0,
                    accountId: $accountId,
                );
            }
            $cursor = $this->advance($cursor, $cadence);
            if ($cursor === null) {
                break;
            }
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
            $this->logCrossUserMismatchIfAny('change_series_amount', $payload->seriesId, $user);

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
        // Preserve the sign of the underlying series; the user enters a
        // magnitude in the form. If the existing contribution is signed
        // negative (expense) and the user enters a positive newAmountMinor,
        // re-apply the negative sign to keep the convention consistent.
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
        $newDate = $this->parsedDate($payload->newNextDate);
        $firstIndex = $this->earliestIndexForSeries($contributions, $payload->seriesId);
        $firstDate = $firstIndex === null ? null : $contributions[$firstIndex]->date;

        // diffInDays returns float in Carbon 3; round to integer days for the
        // addDays() shift below. A zero delta means the series already starts
        // where the mutation asks it to.
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

    private function advance(CarbonImmutable $cursor, string $cadence): ?CarbonImmutable
    {
        return match ($cadence) {
            'weekly' => $cursor->addDays(7),
            'monthly' => $cursor->addMonthNoOverflow(),
            'quarterly' => $cursor->addMonthsNoOverflow(3),
            'yearly' => $cursor->addYearNoOverflow(),
            default => null,
        };
    }

    /**
     * @link ../../../../.docs/features/forecasting/architecture.md
     */
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
