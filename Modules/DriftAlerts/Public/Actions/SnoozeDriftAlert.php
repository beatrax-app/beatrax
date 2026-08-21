<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Events\DriftAlertSnoozed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/drift-alerts/snooze-lifecycle.md
 */
final class SnoozeDriftAlert
{
    public function __construct(
        private readonly DriftAlertStateMachine $stateMachine,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $alertId, User $user, CarbonImmutable $until): void
    {
        /** @var DriftAlert|null $alert */
        $alert = DriftAlert::query()
            ->where('id', $alertId)
            ->where('user_id', $user->id)
            ->first();

        if ($alert === null) {
            throw new NotFoundHttpException('Drift alert not found.');
        }

        if (
            $alert->state === DriftAlertState::Snoozed->value
            && $alert->snoozed_until !== null
            && $alert->snoozed_until->getTimestamp() === $until->getTimestamp()
        ) {
            return;
        }

        $untilString = $until->toDateTimeString();

        $this->stateMachine->transition(
            $alert,
            DriftAlertState::Snoozed->value,
            'user_action',
            'user',
            'snoozed_until='.$untilString,
            ['snoozed_until' => $untilString],
        );

        $this->events->dispatch(new DriftAlertSnoozed(
            userId: $user->id,
            driftAlertId: $alertId,
            snoozedUntil: $until,
        ));
    }
}
