<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Events\RecurringSeriesRejected;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Rejection is permanent until the user un-rejects from the review-queue
// tab; the detector skips rejected clusters on subsequent sweeps so a
// rejected series never re-prompts on its own.

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final class RejectRecurringSeries
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

        if ($series->state === 'rejected') {
            return;
        }

        $this->stateMachine->transition($series, 'rejected', 'user_action', 'user');

        $this->events->dispatch(new RecurringSeriesRejected(
            seriesId: $series->id,
            userId: $user->id,
        ));
    }
}
