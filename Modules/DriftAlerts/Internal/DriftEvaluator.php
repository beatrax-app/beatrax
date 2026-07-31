<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Recurring\Public\Dto\RecurringOccurrenceDto;
use Modules\Recurring\Public\Dto\RecurringSeriesDto;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
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
        $series = $this->eligibleSeries($seriesId, $user);
        if ($series === null) {
            return;
        }

        $occurrences = $this->recurringQuery->occurrencesForSeries($seriesId, $user);
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
     * @return array{priorMinor: int, latestMinor: int, deltaMinor: int, annualized: int, threshold: array{percent: int, source: string}, latestOccurrenceId: int}|null
     */
    private function driftMetrics(RecurringSeriesDto $series, array $occurrences, int $seriesId, User $user): ?array
    {
        $latest = $occurrences[0];
        $prior = $occurrences[1];

        $priorMinor = $prior->observedAmount->toMinor();
        $multiplier = $this->cadenceMultiplierForYear($series->cadence);
        if ($priorMinor === 0 || $multiplier === 0) {
            return null;
        }

        $latestMinor = $latest->observedAmount->toMinor();
        $deltaMinor = $latestMinor - $priorMinor;
        $ratio = abs($deltaMinor) * 100 / abs($priorMinor);
        $threshold = $this->effectiveThresholdPercent($seriesId, $user);
        if ($ratio <= $threshold['percent']) {
            return null;
        }

        return [
            'priorMinor' => $priorMinor,
            'latestMinor' => $latestMinor,
            'deltaMinor' => $deltaMinor,
            'annualized' => $deltaMinor * $multiplier,
            'threshold' => $threshold,
            'latestOccurrenceId' => $latest->occurrenceId,
        ];
    }

    /**
     * @param  array{priorMinor: int, latestMinor: int, deltaMinor: int, annualized: int, threshold: array{percent: int, source: string}, latestOccurrenceId: int}  $drift
     */
    private function openAlert(int $seriesId, User $user, RecurringSeriesDto $series, array $drift): void
    {
        $currency = $series->latestAmount->currency();
        $now = $this->clock->now()->toDateTimeString();

        try {
            $alertId = $this->db->connection()->table('drift_alerts')->insertGetId([
                'user_id' => $user->id,
                'recurring_series_id' => $seriesId,
                'state' => DriftAlertState::Open->value,
                'direction' => $series->direction,
                'baseline_amount_minor' => $drift['priorMinor'],
                'latest_amount_minor' => $drift['latestMinor'],
                'currency' => $currency,
                'delta_minor' => $drift['deltaMinor'],
                'annualized_impact_minor' => $drift['annualized'],
                'threshold_percent_used' => $drift['threshold']['percent'],
                'threshold_source' => $drift['threshold']['source'],
                'latest_occurrence_id' => $drift['latestOccurrenceId'],
                'detected_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->events->dispatch(new DriftAlertOpened(
                userId: $user->id,
                driftAlertId: $alertId,
                recurringSeriesId: $seriesId,
                direction: $series->direction,
                deltaMinor: $drift['deltaMinor'],
                annualizedImpactMinor: $drift['annualized'],
                currency: $currency,
            ));
        } catch (QueryException) {
            // UNIQUE(recurring_series_id, latest_occurrence_id) collision —
            // re-evaluation against the same (series, occurrence) pair is
            // a silent no-op. The idempotency seam is the seam.
        }
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
