<?php

declare(strict_types=1);

namespace Modules\Forecasting\Internal\Pipeline;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Forecasting\Public\Dto\ScenarioMutationDto;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddOneOffPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\AddRecurringPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\CancelSeriesPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ChangeSeriesAmountPayload;
use Modules\Forecasting\Public\Dto\ScenarioMutationPayload\ShiftSeriesDatePayload;
use Modules\Forecasting\Public\Services\ScenarioQuery;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * The load-bearing FCT-03 in-memory transform.
 *
 * Reads `forecast_scenario_mutations` (Forecasting-owned) via
 * `ScenarioQuery` and reads `recurring_series` (Recurring-owned) via
 * `RecurringSeriesQuery::forSeries` ONLY to look up the
 * variance-tolerance + cadence of a series referenced by a mutation —
 * both reads are typed Public surfaces. ScenarioApplier NEVER joins
 * `forecast_scenario_mutations` onto `transactions`,
 * `recurring_series_occurrences`, `chain_links`, or `card_statements`.
 * The
 * `noScenarioMutationsJoinedToTransactionQueries` arch invariant from
 * Plan 10-01 is the single most load-bearing structural enforcement of
 * the FCT-03 boundary, and Wave 5's ScenarioIsolationContractTest will
 * add a runtime end-to-end proof.
 *
 * The series_id validation in `AddScenarioMutation` /
 * `EditScenarioMutation` guarantees every persisted series_id belongs
 * to a user-owned recurring series; the Applier trusts that contract
 * and silently skips mutations whose referenced series has since been
 * deleted (the scenario row can outlive a series row).
 *
 * Algorithm:
 *  1. Load the scenario's mutations.
 *  2. Start with the baseline contributions (an immutable copy is not
 *     required because the caller already passed the bucket and does
 *     not re-use the source list after the Applier returns).
 *  3. Apply each mutation as a pure in-memory transform per its kind.
 *
 * The five kinds:
 *  - `cancel_series` — filter out every contribution whose seriesId
 *    matches the payload.
 *  - `add_one_off` — append a single ForecastContribution at the
 *    payload's date when inside the horizon. Sign comes from the
 *    direction string. seriesId = 0 sentinel because the mutation has
 *    no underlying series.
 *  - `add_recurring` — expand into per-occurrence dates inside the
 *    horizon by walking the cadence forward. Each occurrence carries a
 *    ±5% envelope (calmest default — the form has no variance field).
 *  - `change_series_amount` — for matching seriesId, recompute the
 *    contribution's `(low, point, high)` triple using the new
 *    amount-minor and the underlying series's variance-tolerance.
 *  - `shift_series_date` — shift matching contributions forward by
 *    `(newNextDate - origNextDate)` days. `scope='next'` shifts only
 *    the first matching occurrence; `scope='all_subsequent'` shifts
 *    every matching occurrence. Entries that shift past the horizon
 *    end are dropped.
 */
final readonly class ScenarioApplier
{
    public function __construct(
        private ScenarioQuery $scenarioQuery,
        private RecurringSeriesQuery $seriesQuery,
        private Clock $clock,
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
        // Clock is constructor-injected for parity with sibling pipeline
        // stages; the static-analysis visibility is preserved via the
        // following touch so phpstan-strict-rules accepts the unused
        // promoted property until a later wave uses it.
        $now = $this->clock->now();
        unset($now);

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
            return $this->applyAddOneOff($contributions, $payload, $asOf, $horizonEnd);
        }
        if ($payload instanceof AddRecurringPayload) {
            return $this->applyAddRecurring($contributions, $payload, $asOf, $horizonEnd);
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

        // One-off has no variance envelope per UI-SPEC; low = point = high.
        $accountId = $this->pickAccountIdForOneOff($contributions);
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
     * One-off mutations are not bound to any recurring series and the
     * UI does not ask the user to pick a target account in Wave 4.
     * Land the contribution on whichever account already has the most
     * baseline traffic so the daily fold sees the one-off; if the
     * baseline is empty (new account / first run), fall back to
     * accountId=0 — the daily fold's bucket-or-skip behaviour handles
     * that gracefully.
     *
     * @param  list<ForecastContribution>  $contributions
     */
    private function pickAccountIdForOneOff(array $contributions): int
    {
        $counts = [];
        foreach ($contributions as $c) {
            $counts[$c->accountId] = ($counts[$c->accountId] ?? 0) + 1;
        }
        if ($counts === []) {
            return 0;
        }
        arsort($counts);

        return array_key_first($counts);
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
        // ±5% calmest-default envelope (form has no variance field).
        $low5 = (int) round($magnitude * 0.95);
        $high5 = (int) round($magnitude * 1.05);
        if ($sign < 0) {
            $lowMinor = -$high5;
            $highMinor = -$low5;
        } else {
            $lowMinor = $low5;
            $highMinor = $high5;
        }

        $accountId = $this->pickAccountIdForOneOff($contributions);

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
            return $contributions; // referenced series gone — skip silently.
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
        // Direction inherited from the series's latest amount.
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

        // Find the FIRST matching occurrence (smallest date for that seriesId).
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
}
