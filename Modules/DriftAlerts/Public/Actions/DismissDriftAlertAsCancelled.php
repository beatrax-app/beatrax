<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Internal\StateMachines\DriftAlertStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Events\DriftAlertDismissedCancelled;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Records the user's intent that the underlying recurring series was
 * cancelled outside the app. The drift_alerts row transitions to
 * `dismissed_cancelled`; the recurring_series row stays unchanged
 * (enforced by `noRecurringSeriesWritesFromDriftAlerts` arch test).
 *
 * Idempotent when already dismissed. Cross-user invocation raises
 * NotFoundHttpException. Dispatches `DriftAlertDismissedCancelled` so
 * downstream listeners can exclude the series from their own
 * projections without re-reading the drift_alerts row.
 *
 * Transition reason is `user_dismissed_cancelled` (distinct from the
 * acknowledge action's `user_action`) so the audit trail can separate
 * "reviewed and accepted" from "I cancelled this series."
 */
final class DismissDriftAlertAsCancelled
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

        if ($alert->state === 'dismissed_cancelled') {
            return;
        }

        $now = $this->clock->now();

        $this->stateMachine->transition(
            $alert,
            'dismissed_cancelled',
            'user_dismissed_cancelled',
            'user',
            null,
            ['actioned_at' => $now->toDateTimeString()],
        );

        $this->events->dispatch(new DriftAlertDismissedCancelled(
            userId: $user->id,
            driftAlertId: $alertId,
            recurringSeriesId: $alert->recurring_series_id,
        ));
    }
}
