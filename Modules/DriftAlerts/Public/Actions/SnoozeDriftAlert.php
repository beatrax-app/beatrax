<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertSnoozed;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Snoozes an open drift_alerts row until the supplied timestamp. The
 * state-machine call carries the `snoozed_until` patch in its
 * `$extraColumns` so the state flip and the snooze timestamp land
 * inside the same row-locked transaction and audit row.
 *
 * Idempotent when re-snoozing to the exact same target timestamp
 * (silent no-op). Cross-user invocation raises NotFoundHttpException.
 *
 * Dispatches `DriftAlertSnoozed` so downstream listeners can react to
 * the snooze without re-reading the drift_alerts row.
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

        // Compare via Unix timestamps so the idempotency check stays
        // correct across timezones. `Carbon::toDateTimeString()`
        // formats the moment in the instance's current timezone; the
        // stored `snoozed_until` casts to immutable_datetime in the
        // app timezone while the caller's `$until` may carry a
        // distinct offset from its ISO source. Comparing
        // `getTimestamp()` (seconds since the epoch) is
        // timezone-independent.
        if (
            $alert->state === 'snoozed'
            && $alert->snoozed_until !== null
            && $alert->snoozed_until->getTimestamp() === $until->getTimestamp()
        ) {
            return;
        }

        $untilString = $until->toDateTimeString();

        $this->stateMachine->transition(
            $alert,
            'snoozed',
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
