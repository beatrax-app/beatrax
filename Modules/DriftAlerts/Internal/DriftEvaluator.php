<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Recurring\Public\Enums\SeriesCadence;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * @link ../../../.docs/features/drift-alerts/architecture.md
 */
final readonly class DriftEvaluator
{
    public function __construct(
        private DatabaseManager $db,
        private Clock $clock,
        private RecurringSeriesQuery $recurringQuery,
        private Dispatcher $events,
    ) {}

    public function evaluateForSeries(int $seriesId, User $user): void
    {
        $series = $this->recurringQuery->forSeries($seriesId, $user);
        if ($series === null) {
            return;
        }
        if (! in_array($series->state, ['approved', 'cadence_changed'], true)) {
            return;
        }

        $occurrences = $this->recurringQuery->occurrencesForSeries($seriesId, $user);
        if (count($occurrences) < 2) {
            return;
        }

        $latest = $occurrences[0];
        $prior = $occurrences[1];

        $priorMinor = $prior->observedAmount->toMinor();
        if ($priorMinor === 0) {
            return;
        }

        $latestMinor = $latest->observedAmount->toMinor();
        $deltaMinor = $latestMinor - $priorMinor;
        $ratio = abs($deltaMinor) * 100 / abs($priorMinor);

        $threshold = $this->effectiveThresholdPercent($seriesId, $user);
        if ($ratio <= $threshold['percent']) {
            return;
        }

        $multiplier = $this->cadenceMultiplierForYear($series->cadence);
        if ($multiplier === 0) {
            return;
        }
        $annualized = $deltaMinor * $multiplier;

        $currency = $series->latestAmount->currency();
        $now = $this->clock->now()->toDateTimeString();

        try {
            $alertId = $this->db->connection()->table('drift_alerts')->insertGetId([
                'user_id' => $user->id,
                'recurring_series_id' => $seriesId,
                'state' => 'open',
                'direction' => $series->direction,
                'baseline_amount_minor' => $priorMinor,
                'latest_amount_minor' => $latestMinor,
                'currency' => $currency,
                'delta_minor' => $deltaMinor,
                'annualized_impact_minor' => $annualized,
                'threshold_percent_used' => $threshold['percent'],
                'threshold_source' => $threshold['source'],
                'latest_occurrence_id' => $latest->occurrenceId,
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (QueryException) {
            // UNIQUE(recurring_series_id, latest_occurrence_id) collision —
            // re-evaluation against the same (series, occurrence) pair is
            // a silent no-op. The idempotency seam is the seam.
            return;
        }

        $this->events->dispatch(new DriftAlertOpened(
            userId: $user->id,
            driftAlertId: $alertId,
            recurringSeriesId: $seriesId,
            direction: $series->direction,
            deltaMinor: $deltaMinor,
            annualizedImpactMinor: $annualized,
            currency: $currency,
        ));
    }

    /**
     * @return array{percent: int, source: string} effective threshold for a (series, user)
     *                                             pair — per-series override beats user-global beats the hard 5% default; source is
     *                                             one of series_override/global/default so the audit row and renderer can distinguish
     *                                             them
     */
    private function effectiveThresholdPercent(int $recurringSeriesId, User $user): array
    {
        $seriesOverride = $this->recurringQuery->driftThresholdForSeries($recurringSeriesId, $user);
        if ($seriesOverride !== null) {
            return ['percent' => $seriesOverride, 'source' => 'series_override'];
        }

        $userValue = $user->drift_alert_threshold_percent;
        if ($userValue > 0) {
            return ['percent' => $userValue, 'source' => 'global'];
        }

        return ['percent' => 5, 'source' => 'default'];
    }

    // Irregular annualizes to 0 rather than to a guess: a series with no
    // discernible interval has no meaningful yearly impact, and the zero is
    // what lets callers short-circuit instead of publishing a number they
    // made up.
    /**
     * @return int calendar-year multiplier for the cadence
     */
    private function cadenceMultiplierForYear(SeriesCadence $cadence): int
    {
        return match ($cadence) {
            SeriesCadence::Weekly => 52,
            SeriesCadence::Monthly => 12,
            SeriesCadence::Quarterly => 4,
            SeriesCadence::Yearly => 1,
            SeriesCadence::Irregular => 0,
        };
    }
}
