<?php

declare(strict_types=1);

namespace Modules\Anomaly\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Anomaly\Internal\StateMachines\AnomalyAlertStateMachine;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Events\AnomalyAlertDismissed;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Plain dismiss: the alert leaves the Open tab WITHOUT recording a
// suppression rule (that is DismissAnomalyAlertAsExpected's job).
// Idempotent when already dismissed; cross-user invocation raises
// NotFoundHttpException.
final class DismissAnomalyAlert
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

        if ($alert->state === 'dismissed') {
            return;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            'dismissed',
            'user_dismissed',
            'user',
            null,
            ['dismissed_as' => 'dismissed', 'actioned_at' => $now->toDateTimeString()],
        );

        $this->events->dispatch(new AnomalyAlertDismissed(
            userId: $user->id,
            anomalyAlertId: $alertId,
            dismissedAs: 'dismissed',
        ));
    }
}
