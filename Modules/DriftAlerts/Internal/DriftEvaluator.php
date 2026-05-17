<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Public\Events\DriftAlertOpened;
use Modules\Recurring\Public\Services\RecurringSeriesQuery;

/**
 * Drift detection math.
 *
 * Reads the last two occurrences of an approved or cadence-changed
 * recurring series via Modules\Recurring\Public\Services\RecurringSeriesQuery,
 * computes a signed delta in the series's original currency, applies the
 * effective drift threshold (per-series override beats user global beats
 * the hard 5% floor), guards against divide-by-zero on a prior amount of
 * zero, and INSERTs one drift_alerts row when the ratio crosses the
 * threshold.
 *
 * Idempotency is enforced by the UNIQUE(recurring_series_id,
 * latest_occurrence_id) index on drift_alerts; re-running the evaluator
 * against the same (series, occurrence) pair is silently caught at the
 * QueryException boundary and treated as a no-op.
 *
 * Every cross-module read of recurring_series goes through the
 * RecurringSeriesQuery Public service surface — the evaluator never
 * imports Modules\Recurring\Internal, Modules\Recurring\Models, or
 * runs a raw SELECT against the recurring_series table.
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
     * Resolve the effective drift threshold for a given (series, user)
     * pair: a per-series override wins; otherwise the user-global
     * setting wins; otherwise the hard 5% default applies.
     *
     * The three sources map onto three distinct `threshold_source`
     * labels so the audit row + renderer can distinguish them:
     *   - `series_override` — the user set a per-series override.
     *   - `global`          — the user set a non-zero user-level value.
     *   - `default`         — neither override nor user value applied;
     *                         the hard 5% floor took effect.
     *
     * The per-series override is read through
     * `RecurringSeriesQuery::driftThresholdForSeries` so every
     * cross-module read of `recurring_series` flows through Recurring's
     * Public service surface.
     *
     * @return array{percent: int, source: string}
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

    /**
     * Maps a recurring cadence onto its calendar-year multiplier:
     * weekly => 52, monthly => 12, quarterly => 4, yearly => 1.
     *
     * Returns 0 for any other cadence (including 'irregular') so callers
     * can short-circuit before computing a meaningless annualized
     * impact. The weekly=>52 multiplier stays consistent with the
     * monthly-equivalent ladder used elsewhere (52/12 weekly conversion)
     * at the integer level.
     */
    private function cadenceMultiplierForYear(string $cadence): int
    {
        return match ($cadence) {
            'weekly' => 52,
            'monthly' => 12,
            'quarterly' => 4,
            'yearly' => 1,
            default => 0,
        };
    }
}
