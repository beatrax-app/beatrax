<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Internal\StateMachines\InvalidStateTransitionException;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Core\Public\Contracts\Clock;
use stdClass;

/**
 * Hourly scheduled sweep that flips `anomaly_alerts` rows from
 * `state='snoozed'` to `state='open'` once their `snoozed_until` has
 * elapsed. Cloned from RevivedExpiredDriftSnoozesJob, re-keyed to the
 * anomaly_alerts table + AnomalyAlertStateMachine.
 *
 * Companion to the query-time conditional on
 * `AnomalyAlertQuery::openForUser` (and `openCountForUser`) — those reads
 * widen their state filter to include `state='snoozed' AND snoozed_until
 * <= now()` so the count + list stay honest between sweeps (Pattern 4).
 * The audit row is written exclusively by this sweep: the query-time
 * conditional is a read-side projection, never a write.
 *
 * Runs hourly via `routes/console.php` (a scheduler entry named
 * `anomaly.revive-snoozes`). The sweep is global (no `user_id` scope) —
 * alerts may belong to any user; revival is purely a timer-driven
 * transition, and the user-id is preserved on the audit row by the state
 * machine's read of the alert's owning user (T-09-16: a global revival
 * that only flips snoozed->open exposes no cross-user data).
 *
 * Retries on transient failure: `tries=3` with exponential backoff
 * (60s / 5min / 15min). Each individual transition is idempotent at the
 * state-machine level, so retrying a partially-completed sweep is safe.
 *
 * Concurrent user actions: if a user acknowledges, dismisses, or
 * re-snoozes a candidate row between the SELECT scan and the state-machine
 * call, the state machine sees the new state under its row lock and raises
 * InvalidStateTransitionException. The sweep catches that per-row and skips
 * silently so a single mid-sweep user action cannot fail the entire job
 * (T-09-17: the revival only ever mutates state via the sole-mutator
 * state machine).
 */
final class ReviveExpiredAnomalySnoozesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function handle(DatabaseManager $db, AnomalyAlertStateMachine $stateMachine, Clock $clock): void
    {
        $now = $clock->now()->toDateTimeString();

        $rows = $db->connection()->table('anomaly_alerts')
            ->where('state', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', $now)
            ->get(['id']);

        foreach ($rows as $row) {
            /** @var stdClass $row */
            $id = is_numeric($row->id) ? (int) $row->id : 0;
            if ($id <= 0) {
                continue;
            }

            try {
                /** @var AnomalyAlert|null $alert */
                $alert = AnomalyAlert::query()->where('id', $id)->first();
                if ($alert === null || $alert->state !== 'snoozed') {
                    continue;
                }

                $stateMachine->transition(
                    $alert,
                    'open',
                    'detector_revived_snooze',
                    'detector',
                    null,
                    ['snoozed_until' => null],
                );
            } catch (InvalidStateTransitionException) {
                // A concurrent user action moved the row off 'snoozed'
                // between the candidate scan and the state-machine row
                // lock. The transition guard correctly refused the
                // revival; skip this row and continue the sweep.
                continue;
            }
        }
    }
}
