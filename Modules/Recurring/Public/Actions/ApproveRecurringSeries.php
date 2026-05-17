<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Promotes a recurring_series row from pending / cadence_changed /
 * snoozed to approved. Idempotent when the series is already
 * approved (silent no-op). Cross-user invocation raises
 * NotFoundHttpException (404) via the `(id, user_id)` guard, same
 * defensive pattern Chains + Categorization Public actions use.
 *
 * Dispatches `RecurringSeriesApproved` once the state machine commits
 * the transition; the audit row inside `recurring_series_transitions`
 * is written by the state machine in the same DB transaction.
 */
final class ApproveRecurringSeries
{
    public function __construct(
        private readonly RecurringSeriesStateMachine $stateMachine,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $seriesId, User $user): void
    {
        /** @var RecurringSeries|null $series */
        $series = RecurringSeries::query()
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        if ($series->state === 'approved') {
            return;
        }

        $this->stateMachine->transition($series, 'approved', 'user_action', 'user');

        $this->events->dispatch(new RecurringSeriesApproved(
            seriesId: $series->id,
            userId: $user->id,
        ));
    }
}
