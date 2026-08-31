<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Events\DriftAlertAcknowledged;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class AcknowledgeDriftAlert
{
    public function __construct(
        private DriftAlertStateMachine $stateMachine,
        private Dispatcher $events,
        private Clock $clock,
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

        // A second tab acting on a row the first already closed is a no-op,
        // not a 500: dismissed_cancelled is terminal and has no successor.
        if (! DriftAlertState::from($alert->state)->allows(DriftAlertState::Acknowledged)) {
            return;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            DriftAlertState::Acknowledged->value,
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
