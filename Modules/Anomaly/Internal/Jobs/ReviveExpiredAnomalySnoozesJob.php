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
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use stdClass;

// Global (no `user_id` scope): alerts may belong to any user, and
// revival is purely a timer-driven transition preserved on the audit row
// by the state machine's own read of the alert's owning user.
/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final class ReviveExpiredAnomalySnoozesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    private const CHUNK = 500;

    public function handle(DatabaseManager $db, AnomalyAlertStateMachine $stateMachine, Clock $clock): void
    {
        $now = $clock->now()->toDateTimeString();

        // lazyById so a large backlog of expired snoozes never loads every
        // row into memory at once; each row is re-read fresh under its own
        // row lock inside the callback.
        $db->connection()->table('anomaly_alerts')
            ->where('state', 'snoozed')
            ->whereNotNull('snoozed_until')
            ->where('snoozed_until', '<=', $now)
            ->orderBy('id')
            ->select('id')
            ->lazyById(self::CHUNK)
            ->each(function (stdClass $row) use ($stateMachine): void {
                $id = is_numeric($row->id) ? (int) $row->id : 0;
                if ($id <= 0) {
                    return;
                }

                try {
                    /** @var AnomalyAlert|null $alert */
                    $alert = AnomalyAlert::query()->where('id', $id)->first();
                    if ($alert === null || $alert->state !== 'snoozed') {
                        return;
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
                    // between the scan and the state-machine row lock; skip
                    // this row and continue the sweep.
                }
            });
    }
}
