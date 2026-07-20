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
        $payload = $mutation->payload;

        if ($payload instanceof CancelSeriesPayload) {
            return $this->applyCancelSeries($contributions, $payload);
        }
        if ($payload instanceof AddOneOffPayload) {
            return $this->applyAddOneOff($contributions, $payload, $asOf, $horizonEnd, $user);
        }
        if ($payload instanceof AddRecurringPayload) {
            return $this->applyAddRecurring($contributions, $payload, $asOf, $horizonEnd, $user);
        }
        if ($payload instanceof ChangeSeriesAmountPayload) {
            return $this->applyChangeSeriesAmount($contributions, $payload, $user);
        }
        if ($payload instanceof ShiftSeriesDatePayload) {
            return $this->applyShiftSeriesDate($contributions, $payload, $horizonEnd);
        }

        // Unknown payload — leave contributions untouched; the typed cast
        // already raises on unknown kinds at read time. $horizonDays /
        // $mutation / $user are accepted for symmetry with the per-kind
        // helper signatures.
        unset($horizonDays, $mutation, $user);

        return $contributions;
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
        try {
            $date = CarbonImmutable::parse($payload->date)->startOfDay();
        } catch (\Throwable) {
            return $contributions;
        }
        if ($date->lessThan($asOf) || $date->greaterThan($horizonEnd)) {
            return $contributions;
        }

        $magnitude = abs($payload->amountMinor);
        $sign = $payload->direction === 'income' ? 1 : -1;
        $signed = $sign * $magnitude;

        $accountId = $this->pickAccountIdForOneOff($contributions, $user);
        if ($accountId === 0) {
            // No account to land on (empty baseline AND no owned account);
            // skip the mutation. pickAccountIdForOneOff already logged.
            return $contributions;
        }
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
        try {
            $start = CarbonImmutable::parse($payload->startDate)->startOfDay();
        } catch (\Throwable) {
            return $contributions;
        }
        $cadence = $payload->cadence;
        if (! in_array($cadence, ['weekly', 'monthly', 'quarterly', 'yearly'], true)) {
            return $contributions;
        }

        $magnitude = abs($payload->amountMinor);
        $sign = $payload->direction === 'income' ? 1 : -1;
        $point = $sign * $magnitude;
        // ±5% calmest-default envelope — the add_recurring form has no
        // variance-tolerance field, so a fixed conservative band is used.
        $low5 = (int) round($magnitude * 0.95);
        $high5 = (int) round($magnitude * 1.05);
        if ($sign < 0) {
            $lowMinor = -$high5;
            $highMinor = -$low5;
        } else {
            $lowMinor = $low5;
            $highMinor = $high5;
        }

        $accountId = $this->pickAccountIdForOneOff($contributions, $user);
        if ($accountId === 0) {
            return $contributions;
        }

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
        try {
            $newDate = CarbonImmutable::parse($payload->newNextDate)->startOfDay();
        } catch (\Throwable) {
            return $contributions;
        }

        $firstIndex = null;
        $firstDate = null;
        foreach ($contributions as $i => $c) {
            if ($c->seriesId !== $payload->seriesId) {
                continue;
            }
            if ($firstDate === null || $c->date->lessThan($firstDate)) {
                $firstDate = $c->date;
                $firstIndex = $i;
            }
        }
        if ($firstIndex === null || $firstDate === null) {
            return $contributions;
        }
        // diffInDays returns float in Carbon 3; round to integer days for
        // the addDays() shift below.
        $deltaDays = (int) round($firstDate->diffInDays($newDate, false));
        if ($deltaDays === 0) {
            return $contributions;
        }

        $shiftAll = $payload->scope === 'all_subsequent';

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
