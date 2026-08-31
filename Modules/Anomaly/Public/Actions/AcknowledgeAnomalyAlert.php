<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Anomaly\Public\Events\AnomalyAlertAcknowledged;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AcknowledgeAnomalyAlert
{
    public function __construct(
        private AnomalyAlertStateMachine $stateMachine,
        private Dispatcher $events,
        private Clock $clock,
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

        // A second tab, or the paired device, acting on a row this one still
        // shows as open is a no-op, not a 500: acknowledged is terminal and
        // dismissed leads only back to open.
        if (! AnomalyAlertState::from($alert->state)->allows(AnomalyAlertState::Acknowledged)) {
            return;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            AnomalyAlertState::Acknowledged->value,
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
