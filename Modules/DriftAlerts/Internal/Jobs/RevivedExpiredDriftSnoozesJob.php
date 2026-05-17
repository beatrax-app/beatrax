<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Internal\StateMachines\InvalidStateTransitionException;
use Modules\DriftAlerts\Models\DriftAlert;
use stdClass;

/**
 * Hourly scheduled sweep that flips `drift_alerts` rows from
 * `state='snoozed'` to `state='open'` once their `snoozed_until` has
 * elapsed.
 *
 * Companion to the query-time conditional on `DriftAlertQuery::openForUser`
 * (and the related `openCountForUser` / `totalOpenAnnualizedImpactForUser`
 * aggregates) — those reads widen their state filter to include
 * `state='snoozed' AND snoozed_until <= now()` so the count + sum stay
 * honest between sweeps. The audit row is written exclusively by this
 * sweep: the query-time conditional is a read-side projection, never
 * a write.
 *
 * Runs hourly via `routes/console.php` (a scheduler entry named
 * `drift-alerts.revive-snoozes`). The sweep is global (no `user_id`
 * scope) — alerts may belong to any user; revival is purely a
 * timer-driven transition, the user-id is preserved on the audit row
 * via the state machine's read of the alert's owning user.
 *
 * Retries on transient failure: `tries=3` with exponential backoff
 * (60s / 5min / 15min). Each individual transition is idempotent at
 * the state-machine level, so retrying a partially-completed sweep is
 * safe.
 *
 * Concurrent user actions: if a user acknowledges, dismisses, or
 * re-snoozes a candidate row between the SELECT scan and the
 * state-machine call, the state-machine transaction will see the new
 * state under its row lock and raise `InvalidStateTransitionException`.
 * The sweep catches that exception per-row and skips silently so a
 * single mid-sweep user action cannot fail the entire job.
 */
final class RevivedExpiredDriftSnoozesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300, 900];

    public function handle(DatabaseManager $db, DriftAlertStateMachine $stateMachine, Clock $clock): void
    {
        $now = $clock->now()->toDateTimeString();

        $rows = $db->connection()->table('drift_alerts')
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
                /** @var DriftAlert|null $alert */
                $alert = DriftAlert::query()->where('id', $id)->first();
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
