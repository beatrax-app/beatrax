<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertAcknowledged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Acknowledges an open (or snoozed) drift_alerts row — the user has
 * reviewed the price drift and no further action is required.
 * Idempotent when the alert is already acknowledged (silent no-op).
 * Cross-user invocation raises NotFoundHttpException via the
 * `(id, user_id)` guard.
 *
 * The state machine writes the new state + `actioned_at` audit
 * timestamp inside the same row-locked transaction as the
 * drift_alert_transitions row insert.
 *
 * Acknowledging closes only the alert that was acted on; it has no
 * special effect on future drift detection. If the price stabilises
 * no further alert fires; if it drifts again a fresh alert opens.
 */
final class AcknowledgeDriftAlert
{
    public function __construct(
        private readonly DriftAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $alertId, User $user): void
    {
        /** @var DriftAlert|null $alert */
        $alert = DriftAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Drift alert not found.');
        }

        if ($alert->state === 'acknowledged') {
            return;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            'acknowledged',
            'user_action',
            'user',
            null,
            ['actioned_at' => $now->toDateTimeString()],
        );

        $this->events->dispatch(new DriftAlertAcknowledged(
            userId: $user->id,
            driftAlertId: $alertId,
            acknowledgedAt: $now,
        ));
    }
}
