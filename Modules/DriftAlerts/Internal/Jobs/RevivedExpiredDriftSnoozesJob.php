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
use Modules\DriftAlerts\Models\DriftAlert;
use stdClass;

/**
 * Hourly scheduled sweep that flips `drift_alerts` rows from
 * `state='snoozed'` to `state='open'` once their `snoozed_until` has
 * elapsed.
 *
 * Mirrors Phase 8's `RecurringSeriesStateMachine::expireSnoozes()`
 * pattern but lives in DriftAlerts because the state machine + audit-
 * row contract are DriftAlerts-owned.
 *
 * Companion to the query-time conditional on `DriftAlertQuery::openForUser`
 * (and the related `openCountForUser` / `totalOpenAnnualizedImpactForUser`
 * aggregates) — those reads widen their state filter to include
 * `state='snoozed' AND snoozed_until <= now()` so the count + sum stay
 * honest between sweeps. The audit row is written exclusively by this
 * sweep: the query-time conditional is a read-side projection, never
 * a write.
 *
 * Run hourly via `routes/console.php` (Schedule::job hourly named
 * `drift-alerts.revive-snoozes`). The sweep is global (no `user_id`
 * scope) — alerts may belong to any user; revival is purely a
 * timer-driven transition, the user-id is preserved on the audit row
 * via the state machine's read of the alert's owning user.
 */
final class RevivedExpiredDriftSnoozesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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

            /** @var DriftAlert|null $alert */
            $alert = DriftAlert::query()->where('id', $id)->first();
            if ($alert === null) {
                continue;
            }

            // Defensive guard: a concurrent user action between SELECT
            // and the state-machine call may have flipped the row off
            // 'snoozed'. The allowed-transitions guard would throw, so
            // re-read and skip if the row has already moved.
            if ($alert->state !== 'snoozed') {
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
        }
    }
}
