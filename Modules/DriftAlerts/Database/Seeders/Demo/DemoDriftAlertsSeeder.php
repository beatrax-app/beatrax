<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Database\Seeders\Demo;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Models\DriftAlertTransition;
use Modules\Ledger\Models\Transaction;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Models\RecurringSeriesOccurrence;

/**
 * Materialises a representative drift-alert dataset for the primary
 * demo user so the dashboard's drift-alert banner, the /drift review
 * surface, and the per-series drill-in chart all have data to render:
 *
 *   - One `open` alert with a large `annualized_impact_minor` so the
 *     dashboard banner classifies it as the most prominent tier
 *     (Spotify going from €10.99 → €14.99 = +€48/year impact)
 *   - One `open` alert with a smaller annualized impact (Sport City
 *     going from €25 → €27.50 = +€30/year impact)
 *   - One `acknowledged` alert (Netflix prior price change the user
 *     already reviewed and accepted)
 *   - One `dismissed_cancelled` alert (the NordVPN drift alert the
 *     user dismissed when they cancelled the underlying series)
 *
 * Plus two `drift_alert_transitions` rows narrating the
 * open→acknowledged and open→dismissed_cancelled flips so the audit
 * trail surface has data to render.
 *
 * The `latest_occurrence_id` FK is NOT NULL on `drift_alerts`, so the
 * seeder first upserts a `recurring_series_occurrences` row per target
 * series (each pointing to one of the existing demo transactions for
 * that subscription) and then references that occurrence id from the
 * drift alert. The seeder picks the most recent demo transaction
 * matching the series' detected name as the observation anchor.
 *
 * Idempotency: the UNIQUE `(recurring_series_id, latest_occurrence_id)`
 * on `drift_alerts` keys the upsert; occurrences are upserted via the
 * `(recurring_series_id, transaction_id)` UNIQUE. Transition rows are
 * upserted via the application-keyed
 * `(drift_alert_id, from_state, to_state)` triple — sufficient because
 * the demo set carries one transition per (alert, flip) pair.
 *
 * State mutations on existing rows route through
 * `DriftAlertStateMachine`; the seeder lands alerts already in the
 * target state via `create()` (the boundary arch test only blocks
 * `DriftAlert::query()->update(['state' => …])`, not initial inserts).
 */
final class DemoDriftAlertsSeeder
{
    /**
     * Per-alert demo configuration. `seriesClusterKey` resolves to the
     * existing demo recurring series; `txDescription` selects the
     * underlying transaction the occurrence row anchors to. Amounts
     * are signed minor units; expense direction means the delta should
     * be negative to model "you are paying more than baseline".
     *
     * @var list<array{seriesClusterKey: string, txDescription: string, state: string, direction: string, baselineMinor: int, latestMinor: int, deltaMinor: int, annualMinor: int, thresholdPercent: int, ageDays: int, actionedAgeDays: ?int}>
     */
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

    /**
     * Lifecycle transitions narrating the moments alerts left the
     * `open` state. Keyed by `seriesClusterKey` so the application
     * resolves alert ids deterministically at seed time.
     *
     * @var list<array{seriesClusterKey: string, fromState: string, toState: string, reason: string, actor: string, ageDays: int, notes: ?string}>
     */
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
        $series = RecurringSeries::query()
            ->where('user_id', $user->id)
            ->where('cluster_key', $row['seriesClusterKey'])
            ->first();

        if ($series === null) {
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

        $occurrence = $this->ensureOccurrence($user, $series, $transaction);
        $today = CarbonImmutable::today();
        $detectedAt = $today->subDays($row['ageDays'])->setTime(12, 0);
        $actionedAt = $row['actionedAgeDays'] === null
            ? null
            : $today->subDays($row['actionedAgeDays'])->setTime(12, 0);

        $existing = DriftAlert::query()
            ->where('recurring_series_id', $series->id)
            ->where('latest_occurrence_id', $occurrence->id)
            ->first();

        if ($existing !== null) {
            return;
        }

        DriftAlert::query()->create([
            'user_id' => $user->id,
            'recurring_series_id' => $series->id,
            'state' => $row['state'],
            'direction' => $row['direction'],
            'baseline_amount_minor' => $row['baselineMinor'],
            'latest_amount_minor' => $row['latestMinor'],
            'currency' => 'EUR',
            'delta_minor' => $row['deltaMinor'],
            'annualized_impact_minor' => $row['annualMinor'],
            'threshold_percent_used' => $row['thresholdPercent'],
            'threshold_source' => 'user_default',
            'latest_occurrence_id' => $occurrence->id,
            'snoozed_until' => null,
            'detected_at' => $detectedAt,
            'actioned_at' => $actionedAt,
        ]);
    }

    private function ensureOccurrence(User $user, RecurringSeries $series, Transaction $tx): RecurringSeriesOccurrence
    {
        /** @var RecurringSeriesOccurrence $occurrence */
        $occurrence = RecurringSeriesOccurrence::query()->updateOrCreate(
            [
                'recurring_series_id' => $series->id,
                'transaction_id' => $tx->id,
            ],
            [
                'user_id' => $user->id,
                'observed_at' => $tx->posted_at,
                'observed_amount_minor' => $tx->amount_minor,
                'observed_currency' => $tx->currency,
            ],
        );

        return $occurrence;
    }

    private function upsertTransitionsForUser(User $user): void
    {
        $today = CarbonImmutable::today();

        foreach (self::TRANSITIONS as $row) {
            $series = RecurringSeries::query()
                ->where('user_id', $user->id)
                ->where('cluster_key', $row['seriesClusterKey'])
                ->first();

            if ($series === null) {
                continue;
            }

            $alert = DriftAlert::query()
                ->where('recurring_series_id', $series->id)
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
