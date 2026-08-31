<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SnoozeUntil;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\DriftAlerts\Public\Events\DriftAlertSnoozed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/drift-alerts/snooze-lifecycle.md
 */
final readonly class SnoozeDriftAlert
{
    public function __construct(
        private DriftAlertStateMachine $stateMachine,
        private Dispatcher $events,
        private Clock $clock,
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

        // The 404 guard runs first so a cross-user probe never learns whether
        // its tampered target was in range.
        $bounded = SnoozeUntil::from($until, $this->clock->now());
        $untilString = $bounded->toDateTimeString();

        // Compare through the same toDateTimeString() round-trip the stored
        // value took: it drops sub-second precision and the source offset, so
        // a raw timestamp comparison would miss an identical re-snooze.
        $isSnoozed = $alert->state === DriftAlertState::Snoozed->value;
        if ($isSnoozed && $alert->snoozed_until?->toDateTimeString() === $untilString) {
            return;
        }

        if (! DriftAlertState::from($alert->state)->allows(DriftAlertState::Snoozed)) {
            return;
        }

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
            snoozedUntil: $bounded->at,
        ));
    }
}
