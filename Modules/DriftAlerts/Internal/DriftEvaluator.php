<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\DerivedRowId;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\Core\Public\Support\QueryFailure;
use Modules\DriftAlerts\Internal\Enums\ThresholdSource;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringOccurrenceQuery;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;
use Modules\Sync\Public\Events\EntityMutated;

/**
 * @link ../../../.docs/features/drift-alerts/drift-detection.md
 */
final readonly class DriftEvaluator
{
    private const float DAYS_PER_YEAR = 365.0;

    // Two for the movement, a third for the interval the prior amount was
    // billed over. Reading the whole history to reach them hydrated a DTO per
    // occurrence for a series that may have hundreds.
    private const int OCCURRENCES_READ = 3;

    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
        private RecurringOccurrenceQuery $occurrenceQuery,
        private Dispatcher $events,
    ) {}

    public function evaluateForSeries(int $seriesId, User $user): void
    {
        $series = $this->eligibleSeries($seriesId, $user);
        if ($series === null) {
            return;
        }

        $occurrences = $this->occurrenceQuery->latestOccurrencesForSeries($seriesId, $user, self::OCCURRENCES_READ);
        if (count($occurrences) < 2) {
            return;
        }

        $drift = $this->driftMetrics($series, $occurrences, $seriesId, $user);
        if ($drift === null) {
            return;
        }

        $this->openAlert($seriesId, $user, $series, $drift);
    }

    private function eligibleSeries(int $seriesId, User $user): ?RecurringSeriesDto
    {
        $series = $this->recurringQuery->forSeries($seriesId, $user);
        if ($series === null) {
            return null;
        }

        $approved = [RecurringSeriesState::Approved->value, RecurringSeriesState::CadenceChanged->value];

        return in_array($series->state, $approved, true) ? $series : null;
    }

    /**
     * @param  list<RecurringOccurrenceDto>  $occurrences
     */
    private function driftMetrics(RecurringSeriesDto $series, array $occurrences, int $seriesId, User $user): ?DriftMetrics
    {
        $latest = $occurrences[0];
        $prior = $occurrences[1];

        $multiplier = self::cadenceMultiplierForYear($series->cadence);
        if ($multiplier === 0) {
            return null;
        }

        $movement = AmountMovement::between($prior->observedAmount, $latest->observedAmount);
        if ($movement === null) {
            return null;
        }

        // The alert is denominated in the series currency, so a movement priced
        // in anything else would be stamped with a currency it is not in.
        if ($prior->observedAmount->currency() !== $series->latestAmount->currency()) {
            return null;
        }

        [$thresholdPercent, $thresholdSource] = $this->effectiveThreshold($seriesId, $user);
        if ($movement->ratioPercent <= $thresholdPercent) {
            return null;
        }

        return new DriftMetrics(
            priorMinor: $movement->priorMinor,
            latestMinor: $movement->latestMinor,
            deltaMinor: $movement->deltaMinor,
            annualizedMinor: $movement->annualImpactMinor(
                self::priorOccurrencesPerYear($occurrences, $multiplier),
                $multiplier,
            ),
            thresholdPercent: $thresholdPercent,
            thresholdSource: $thresholdSource,
            latestOccurrenceId: $latest->occurrenceId,
        );
    }

    // The billing period the PRIOR amount covered, read off the gap before it.
    // A series whose cadence was just restructured carries the new cadence, and
    // annualising the old amount at the new rate reported a saving as a rise.
    /**
     * @param  list<RecurringOccurrenceDto>  $occurrences
     */
    private static function priorOccurrencesPerYear(array $occurrences, int $fallback): int
    {
        if (count($occurrences) < 3) {
            return $fallback;
        }

        $gapDays = $occurrences[2]->observedAt->diffInDays($occurrences[1]->observedAt);
        if ($gapDays <= 0.0) {
            return $fallback;
        }

        return self::snapToCadenceRate(self::DAYS_PER_YEAR / $gapDays) ?? $fallback;
    }

    // Nearest by ratio, not by difference: 4/yr and 1/yr are 3 apart while
    // 52/yr and 12/yr are 40, so a linear nearest-match would pull every long
    // gap onto the yearly band.
    private static function snapToCadenceRate(float $perYear): ?int
    {
        $best = null;
        $bestDistance = null;
        foreach (SeriesCadence::cases() as $cadence) {
            $rate = self::cadenceMultiplierForYear($cadence);
            if ($rate === 0) {
                continue;
            }
            $distance = abs(log($perYear / $rate));
            if ($bestDistance === null || $distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $rate;
            }
        }

        return $best;
    }

    private function openAlert(int $seriesId, User $user, RecurringSeriesDto $series, DriftMetrics $drift): void
    {
        $currency = $series->latestAmount->currency();
        $now = $this->clock->now()->toDateTimeString();

        // Derived, not minted: the detector runs on every paired device, and an
        // autoincrement gave each a different id for one subscription's rise.
        // Both halves are the table's own UNIQUE and neither ever moves, so the
        // second device's create collides harmlessly instead of duplicating.
        $alertId = DerivedRowId::for('drift_alerts', [
            'recurring_series_id' => $seriesId,
            'latest_occurrence_id' => $drift->latestOccurrenceId,
        ]);

        $row = [
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'state' => DriftAlertState::Open->value,
            'direction' => $series->direction,
            'baseline_amount_minor' => $drift->priorMinor,
            'latest_amount_minor' => $drift->latestMinor,
            'currency' => $currency,
            'delta_minor' => $drift->deltaMinor,
            'annualized_impact_minor' => $drift->annualizedMinor,
            'threshold_percent_used' => $drift->thresholdPercent,
            'threshold_source' => $drift->thresholdSource->value,
            'latest_occurrence_id' => $drift->latestOccurrenceId,
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        try {
            $this->db->connection()->table('drift_alerts')->insert(['id' => $alertId] + $row);
        } catch (QueryException $e) {
            // Only the UNIQUE(recurring_series_id, latest_occurrence_id)
            // re-detect is a no-op. Anything else — a lock timeout, a full
            // disk — was suppressing the alert permanently and silently.
            if (! QueryFailure::isUniqueViolation($e)) {
                throw $e;
            }

            return;
        }

        // Outside the insert's try: a listener that throws must not be read as
        // a duplicate and cost the row its notification.
        $this->events->dispatch(new DriftAlertOpened(
            userId: $user->id,
            driftAlertId: $alertId,
            recurringSeriesId: $seriesId,
            direction: $series->direction,
            deltaMinor: $drift->deltaMinor,
            annualizedImpactMinor: $drift->annualizedMinor,
            currency: $currency,
        ));

        // `$row` omits `id` on purpose: the pk carries it, and this is the same
        // create-op shape the pairing backfill emits.
        $this->events->dispatch(new EntityMutated(
            table: 'drift_alerts',
            pk: $alertId,
            userId: $user->id,
            mutationType: 'create',
            dirtyFields: $row,
        ));
    }

    // A per-series override beats the user-global setting, which beats the
    // hard default; the source rides onto the row so the audit and the
    // renderer can tell the reader which of the three judged their charge.
    /**
     * @return array{0: int, 1: ThresholdSource}
     */
    private function effectiveThreshold(int $recurringSeriesId, User $user): array
    {
        $seriesOverride = $this->recurringQuery->driftThresholdForSeries($recurringSeriesId, $user);
        if ($seriesOverride !== null) {
            return [$seriesOverride, ThresholdSource::SeriesOverride];
        }

        $userValue = $user->drift_alert_threshold_percent;
        if ($userValue > 0) {
            return [$userValue, ThresholdSource::Global];
        }

        return [DriftThresholdOptions::DEFAULT_PERCENT, ThresholdSource::Default];
    }

    /**
     * @return int calendar-year multiplier for the cadence
     */
    private static function cadenceMultiplierForYear(SeriesCadence $cadence): int
    {
        return CadenceYearRate::forCadence($cadence);
    }
}
