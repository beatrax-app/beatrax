<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertAcknowledged;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Idempotent when already acknowledged (silent no-op). Cross-user
// invocation raises NotFoundHttpException via the `(id, user_id)` guard.
// Acknowledging closes only the alert acted on; future detection is
// unaffected.
final class AcknowledgeAnomalyAlert
{
    public function __construct(
        private readonly AnomalyAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
        private readonly Clock $clock,
    ) {}

    public function __invoke(int $alertId, User $user): void
    {
        /** @var AnomalyAlert|null $alert */
        $alert = AnomalyAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Anomaly alert not found.');
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

        $this->events->dispatch(new AnomalyAlertAcknowledged(
            userId: $user->id,
            anomalyAlertId: $alertId,
            acknowledgedAt: $now,
        ));
    }
}
