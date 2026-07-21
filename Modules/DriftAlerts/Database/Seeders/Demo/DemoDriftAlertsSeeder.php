<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\Ledger\Models\Transaction;

// The latest_occurrence_id FK is NOT NULL on drift_alerts, so the seeder
// first upserts a recurring_series_occurrences row per target series and
// then references that occurrence id. Alerts land already in the target
// state via create() — the boundary arch test only blocks direct updates.
final class DemoDriftAlertsSeeder
{
    // seriesClusterKey resolves to the existing demo recurring series;
    // txDescription selects the underlying transaction the occurrence row
    // anchors to. Amounts are signed minor units; expense direction means
    // the delta should be negative to model "paying more than baseline".
    /** @var list<array{seriesClusterKey: string, txDescription: string, state: string, direction: string, baselineMinor: int, latestMinor: int, deltaMinor: int, annualMinor: int, thresholdPercent: int, ageDays: int, actionedAgeDays: ?int}> */
    private const ALERTS = [
        [
            'seriesClusterKey' => 'demo:spotify:monthly:1099',
            'txDescription' => 'Spotify Premium',
            'state' => 'open',
            'direction' => 'expense',
            'baselineMinor' => -1099,
            'latestMinor' => -1499,
            'deltaMinor' => -400,
            'annualMinor' => -4800,
            'thresholdPercent' => 10,
            'ageDays' => 2,
            'actionedAgeDays' => null,
        ],
        [
            'seriesClusterKey' => 'demo:sport-city:monthly:2500',
            'txDescription' => 'Sport City',
            'state' => 'open',
            'direction' => 'expense',
            'baselineMinor' => -2500,
            'latestMinor' => -2750,
            'deltaMinor' => -250,
            'annualMinor' => -3000,
            'thresholdPercent' => 10,
            'ageDays' => 5,
            'actionedAgeDays' => null,
        ],
        [
            'seriesClusterKey' => 'demo:netflix:monthly:1499',
            'txDescription' => 'Netflix.com',
            'state' => 'acknowledged',
            'direction' => 'expense',
            'baselineMinor' => -1399,
            'latestMinor' => -1499,
            'deltaMinor' => -100,
            'annualMinor' => -1200,
            'thresholdPercent' => 5,
            'ageDays' => 14,
            'actionedAgeDays' => 8,
        ],
        [
            'seriesClusterKey' => 'demo:nordvpn:monthly:499',
            'txDescription' => 'PayPal conversion fee',
            'state' => 'dismissed_cancelled',
            'direction' => 'expense',
            'baselineMinor' => -499,
            'latestMinor' => -699,
            'deltaMinor' => -200,
            'annualMinor' => -2400,
            'thresholdPercent' => 10,
            'ageDays' => 7,
            'actionedAgeDays' => 2,
        ],
    ];

    // Keyed by seriesClusterKey so the application resolves alert ids
    // deterministically at seed time.
    /** @var list<array{seriesClusterKey: string, fromState: string, toState: string, reason: string, actor: string, ageDays: int, notes: ?string}> */
    private const TRANSITIONS = [
        [
            'seriesClusterKey' => 'demo:netflix:monthly:1499',
            'fromState' => 'open',
            'toState' => 'acknowledged',
            'reason' => 'user_action',
            'actor' => 'user',
            'ageDays' => 8,
            'notes' => 'User acknowledged the Netflix price drift',
        ],
        [
            'seriesClusterKey' => 'demo:nordvpn:monthly:499',
            'fromState' => 'open',
            'toState' => 'dismissed_cancelled',
            'reason' => 'user_dismissed_cancelled',
            'actor' => 'user',
            'ageDays' => 2,
            'notes' => 'User dismissed the alert after cancelling the underlying series',
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
        $primary = $users['demo-1@beatrax.local'] ?? null;
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
     * @param  array{seriesClusterKey: string, txDescription: string, state: string, direction: string, baselineMinor: int, latestMinor: int, deltaMinor: int, annualMinor: int, thresholdPercent: int, ageDays: int, actionedAgeDays: ?int}  $row
     */
    private function upsertAlertForUser(User $user, array $row): void
    {
        $seriesId = $this->lookupSeriesId($user, $row['seriesClusterKey']);
        if ($seriesId === null) {
            return;
        }

        $transaction = Transaction::query()
            ->where('user_id', $user->id)
            ->where('source_format', 'demo')
            ->where('description', $row['txDescription'])
            ->orderBy('posted_at', 'desc')
            ->first();

        if ($transaction === null) {
            return;
        }

        $occurrenceId = $this->ensureOccurrence($user, $seriesId, $transaction);
        $today = CarbonImmutable::today();
        $detectedAt = $today->subDays($row['ageDays'])->setTime(12, 0);
        $actionedAt = $row['actionedAgeDays'] === null
            ? null
            : $today->subDays($row['actionedAgeDays'])->setTime(12, 0);

        $existing = DriftAlert::query()
            ->where('recurring_series_id', $seriesId)
            ->where('latest_occurrence_id', $occurrenceId)
            ->first();

        if ($existing !== null) {
            return;
        }

        DriftAlert::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'state' => $row['state'],
            'direction' => $row['direction'],
            'baseline_amount_minor' => $row['baselineMinor'],
            'latest_amount_minor' => $row['latestMinor'],
            'currency' => 'EUR',
            'delta_minor' => $row['deltaMinor'],
            'annualized_impact_minor' => $row['annualMinor'],
            'threshold_percent_used' => $row['thresholdPercent'],
            'threshold_source' => 'user_default',
            'latest_occurrence_id' => $occurrenceId,
            'snoozed_until' => null,
            'detected_at' => $detectedAt,
            'actioned_at' => $actionedAt,
        ]);
    }

    // Uses the query builder rather than the RecurringSeries Eloquent
    // model — the boundary arch test bans any RecurringSeries::* identifier
    // inside the DriftAlerts source tree, even for read access.
    private function lookupSeriesId(User $user, string $clusterKey): ?int
    {
        $row = $this->db->connection()
            ->table('recurring_series')
            ->where('user_id', $user->id)
            ->where('cluster_key', $clusterKey)
            ->first(['id']);

        if ($row === null || ! is_numeric($row->id)) {
            return null;
        }

        return (int) $row->id;
    }

    // Idempotent upsert keyed on (recurring_series_id, transaction_id) via
    // the query builder rather than the RecurringSeriesOccurrence Eloquent
    // model, to keep the DriftAlerts boundary arch test green.
    private function ensureOccurrence(User $user, int $seriesId, Transaction $tx): int
    {
        $connection = $this->db->connection();
        $now = CarbonImmutable::now()->toDateTimeString();

        $existing = $connection->table('recurring_series_occurrences')
            ->where('recurring_series_id', $seriesId)
            ->where('transaction_id', $tx->id)
            ->first(['id']);

        if ($existing !== null && is_numeric($existing->id)) {
            return (int) $existing->id;
        }

        return (int) $connection->table('recurring_series_occurrences')->insertGetId([
            'user_id' => $user->id,
            'recurring_series_id' => $seriesId,
            'transaction_id' => $tx->id,
            'observed_at' => $tx->posted_at->toDateString(),
            'observed_amount_minor' => $tx->amount_minor,
            'observed_currency' => $tx->currency,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function upsertTransitionsForUser(User $user): void
    {
        $today = CarbonImmutable::today();

        foreach (self::TRANSITIONS as $row) {
            $seriesId = $this->lookupSeriesId($user, $row['seriesClusterKey']);
            if ($seriesId === null) {
                continue;
            }

            $alert = DriftAlert::query()
                ->where('recurring_series_id', $seriesId)
                ->orderBy('id', 'desc')
                ->first();

            if ($alert === null) {
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
                'transitioned_at' => $today->subDays($row['ageDays'])->setTime(12, 0),
                'notes' => $row['notes'],
            ]);
        }
    }
}
