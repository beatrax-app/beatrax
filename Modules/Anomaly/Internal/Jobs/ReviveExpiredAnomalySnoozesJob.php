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
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;
use Modules\Core\Public\Support\RowChunk;
use stdClass;

// Deliberately unscoped by `user_id`: revival is a pure timer transition, and
// the state machine reads each alert's own owner for the audit row.
final class ReviveExpiredAnomalySnoozesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    private const int CHUNK = RowChunk::DEFAULT_SIZE;

    public function handle(DatabaseManager $db, AnomalyAlertStateMachine $stateMachine, Clock $clock): void
    {
        $now = $clock->now()->toDateTimeString();

        $db->connection()->table('anomaly_alerts')
            ->where('state', AnomalyAlertState::Snoozed->value)
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
                    if ($alert === null || $alert->state !== AnomalyAlertState::Snoozed->value) {
                        return;
                    }

                    $stateMachine->transition(
                        $alert,
                        AnomalyAlertState::Open->value,
                        'detector_revived_snooze',
                        'detector',
                        null,
                        ['snoozed_until' => null],
                    );
                } catch (InvalidStateTransitionException) {
                    // A concurrent user action moved the row off 'snoozed'
                    // between the scan and the row lock; skip it, sweep on.
                }
            });
    }
}
