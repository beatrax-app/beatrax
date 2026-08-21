<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Core\Public\Concerns\TunedQueueJob;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\StateMachine\InvalidStateTransitionException;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use stdClass;

final class RevivedExpiredDriftSnoozesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TunedQueueJob;

    public function handle(DatabaseManager $db, DriftAlertStateMachine $stateMachine, Clock $clock): void
    {
        $now = $clock->now()->toDateTimeString();

        $rows = $db->connection()->table('drift_alerts')
            ->where('state', DriftAlertState::Snoozed->value)
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
                if ($alert === null || $alert->state !== DriftAlertState::Snoozed->value) {
                    continue;
                }

                $stateMachine->transition(
                    $alert,
                    DriftAlertState::Open->value,
                    'detector_revived_snooze',
                    'detector',
                    null,
                    ['snoozed_until' => null],
                );
            } catch (InvalidStateTransitionException) {
                // A concurrent user action moved the row off 'snoozed' between
                // the candidate scan and the row lock, so the guard was right
                // to refuse; the sweep carries on.
                continue;
            }
        }
    }
}
