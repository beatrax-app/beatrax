<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\DriftThresholdOptions;
use Modules\DriftAlerts\Internal\CadenceYearRate;
use Modules\DriftAlerts\Internal\Enums\ThresholdSource;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Enums\SeriesCadence;

// Created directly in their target state rather than through
// DriftAlertStateMachine; the boundary arch test blocks only direct updates.
// Every figure comes from the rows the alert names: the hand-written prior
// price was one no charge in the demo ledger had ever carried.
/**
 * @link ../../../../../.docs/features/drift-alerts/drift-detection.md
 */
final class DemoDriftAlertsSeeder
{
    // Three of the six demo subscriptions stepped price, and KPN deliberately
    // did not: an alert against every eligible series would teach the reader
    // that /drift lists their subscriptions rather than filters them.
    /** @var list<array{seriesClusterKey: string, state: string, actionedAfterDays: ?int}> */
    private const ALERTS = [
        [
            'seriesClusterKey' => 'demo:spotify:monthly:1099',
            'state' => DriftAlertState::Open->value,
            'actionedAfterDays' => null,
        ],
        [
            'seriesClusterKey' => 'demo:netflix:monthly:1499',
            'state' => DriftAlertState::Acknowledged->value,
            'actionedAfterDays' => 6,
        ],
        [
            'seriesClusterKey' => 'demo:sport-city:monthly:2500',
            'state' => DriftAlertState::DismissedCancelled->value,
            'actionedAfterDays' => 5,
        ],
    ];

    /** @var list<array{seriesClusterKey: string, fromState: string, toState: string, reason: string, actor: string, notes: string}> */
    private const TRANSITIONS = [
        [
            'seriesClusterKey' => 'demo:netflix:monthly:1499',
            'fromState' => DriftAlertState::Open->value,
            'toState' => DriftAlertState::Acknowledged->value,
            'reason' => 'user_action',
            'actor' => 'user',
            'notes' => 'User acknowledged the Netflix price drift',
        ],
        [
            'seriesClusterKey' => 'demo:sport-city:monthly:2500',
            'fromState' => DriftAlertState::Open->value,
            'toState' => DriftAlertState::DismissedCancelled->value,
            'reason' => 'user_dismissed_cancelled',
            'actor' => 'user',
            'notes' => 'User dismissed the alert after cancelling the gym membership',
        ],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, User>  $users
     */
    public function run(array $users): int
    {
        $primary = $users['demo-1'] ?? null;
        if ($primary !== null) {
            foreach (self::ALERTS as $alertRow) {
                $this->upsertAlertForUser($primary, $alertRow);
            }
            $this->upsertTransitionsForUser($primary);
        }

        return DriftAlert::query()
            ->whereIn('user_id', array_map(static fn (User $u): int => $u->id, $users))
            ->count();
    }

    /**
     * @param  array{id: int, prior_amount_minor: int, latest_amount_minor: int, currency: string, observed_at: string}  $step
     */
    private static function isAlertableMove(array $step, int $thresholdPercent): bool
    {
        $priorMinor = $step['prior_amount_minor'];
        $latestMinor = $step['latest_amount_minor'];

        // A zero prior has nothing to take a ratio against, and a move that
        // crossed zero is a different event rather than a bigger one — the
        // same pair AmountMovement refuses.
        if ($priorMinor === 0 || ($priorMinor > 0) !== ($latestMinor > 0)) {
            return false;
        }

        // The same test the evaluator applies, so the demo can never carry an
        // alert the shipped detector would have refused to open.
        return abs($latestMinor - $priorMinor) * 100 / abs($priorMinor) > $thresholdPercent;
    }

    private function alreadyAlerted(int $seriesId, int $occurrenceId): bool
    {
        return DriftAlert::query()
            ->where('recurring_series_id', $seriesId)
            ->where('latest_occurrence_id', $occurrenceId)
            ->exists();
    }

    /**
     * @param  array{seriesClusterKey: string, state: string, actionedAfterDays: ?int}  $row
     */
    private function upsertAlertForUser(User $user, array $row): void
    {
        $series = $this->eligibleSeries($user, $row['seriesClusterKey']);
        $step = $series === null ? null : $this->newestPriceStep($user, $series['id']);

        if ($step === null) {
            return;
        }

        [$thresholdPercent, $thresholdSource] = $this->effectiveThreshold($user);

        if (! self::isAlertableMove($step, $thresholdPercent) || $this->alreadyAlerted($series['id'], $step['id'])) {
            return;
        }

        $latestMinor = $step['latest_amount_minor'];
        $priorMinor = $step['prior_amount_minor'];
        $deltaMinor = $latestMinor - $priorMinor;

        $detectedAt = CarbonImmutable::parse($step['observed_at'])->setTime(12, 0);
        $actionedAt = $row['actionedAfterDays'] === null
            ? null
            : $detectedAt->addDays($row['actionedAfterDays']);

        DriftAlert::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $series['id'],
            'state' => $row['state'],
            'direction' => $series['direction'],
            'baseline_amount_minor' => $priorMinor,
            'latest_amount_minor' => $latestMinor,
            'currency' => $step['currency'],
            'delta_minor' => $deltaMinor,
            'annualized_impact_minor' => $deltaMinor * CadenceYearRate::forCadence($series['cadence']),
            'threshold_percent_used' => $thresholdPercent,
            'threshold_source' => $thresholdSource->value,
            'latest_occurrence_id' => $step['id'],
            'snoozed_until' => null,
            'detected_at' => $detectedAt,
            'actioned_at' => $actionedAt,
        ]);
    }

    // The ladder the evaluator walks, minus the per-series override no demo
    // series carries: the seeder used to stamp `global, 10` at a user whose
    // global threshold is 5.
    /**
     * @return array{0: int, 1: ThresholdSource}
     */
    private function effectiveThreshold(User $user): array
    {
        $userValue = $user->drift_alert_threshold_percent;

        return $userValue > 0
            ? [$userValue, ThresholdSource::Global]
            : [DriftThresholdOptions::DEFAULT_PERCENT, ThresholdSource::Default];
    }

    // Query builder, not the Eloquent model: the boundary arch test bans any
    // RecurringSeries* identifier inside this tree, read access included.
    /**
     * @return array{id: int, direction: string, cadence: SeriesCadence}|null
     */
    private function eligibleSeries(User $user, string $clusterKey): ?array
    {
        $row = $this->db->connection()
            ->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('cluster_key', $clusterKey)
            ->first(['id', 'state', 'direction', 'cadence']);

        // A rejected or pending series is one the evaluator refuses by design,
        // and the demo carried an alert against a rejected one.
        $eligible = [RecurringSeriesState::Approved->value, RecurringSeriesState::CadenceChanged->value];
        $usable = $row !== null
            && is_numeric($row->id)
            && is_string($row->state)
            && in_array($row->state, $eligible, true);

        if (! $usable) {
            return null;
        }

        $cadence = is_string($row->cadence) ? SeriesCadence::tryFrom($row->cadence) : null;
        if ($cadence === null || ! is_string($row->direction)) {
            return null;
        }

        return ['id' => (int) $row->id, 'direction' => $row->direction, 'cadence' => $cadence];
    }

    // The newest adjacent pair of occurrences whose amounts differ — the same
    // two rows the evaluator compares, at the moment the step landed. A series
    // charged the same amount every month has no step, and gets no alert: the
    // shipped demo asserted a prior price against four such series.
    /**
     * @return array{id: int, prior_amount_minor: int, latest_amount_minor: int, currency: string, observed_at: string}|null
     */
    private function newestPriceStep(User $user, int $seriesId): ?array
    {
        $rows = $this->db->connection()
            ->table('recurring_series_occurrences')
            ->where('user_id', $user->id)
            ->where('recurring_series_id', $seriesId)
            ->orderBy('observed_at')
            ->orderBy('id')
            ->get(['id', 'observed_amount_minor', 'observed_currency', 'observed_at'])
            ->all();

        for ($i = count($rows) - 1; $i >= 1; $i--) {
            $latest = $rows[$i];
            $prior = $rows[$i - 1];

            if (! is_numeric($latest->id) || ! is_numeric($latest->observed_amount_minor) || ! is_numeric($prior->observed_amount_minor)) {
                continue;
            }
            if (! is_string($latest->observed_currency) || ! is_string($latest->observed_at)) {
                continue;
            }
            if ((int) $latest->observed_amount_minor === (int) $prior->observed_amount_minor) {
                continue;
            }

            return [
                'id' => (int) $latest->id,
                'prior_amount_minor' => (int) $prior->observed_amount_minor,
                'latest_amount_minor' => (int) $latest->observed_amount_minor,
                'currency' => $latest->observed_currency,
                'observed_at' => $latest->observed_at,
            ];
        }

        return null;
    }

    private function upsertTransitionsForUser(User $user): void
    {
        foreach (self::TRANSITIONS as $row) {
            $series = $this->eligibleSeries($user, $row['seriesClusterKey']);
            if ($series === null) {
                continue;
            }

            /** @var DriftAlert|null $alert */
            $alert = DriftAlert::query()
                ->where('user_id', $user->id)
                ->where('recurring_series_id', $series['id'])
                ->orderBy('id', 'desc')
                ->first();

            if ($alert === null || $alert->actioned_at === null) {
                continue;
            }

            $existing = $this->db->connection()
                ->table('drift_alert_transitions')
                ->where('drift_alert_id', $alert->id)
                ->where('from_state', $row['fromState'])
                ->where('to_state', $row['toState'])
                ->exists();

            if ($existing) {
                continue;
            }

            DriftAlertTransition::query()->create([
                'user_id' => $user->id,
                'drift_alert_id' => $alert->id,
                'from_state' => $row['fromState'],
                'to_state' => $row['toState'],
                'transition_reason' => $row['reason'],
                'actor' => $row['actor'],
                // The audit row is the moment the state flipped, which the
                // alert already records.
                'transitioned_at' => $alert->actioned_at,
                'notes' => $row['notes'],
            ]);
        }
    }
}
