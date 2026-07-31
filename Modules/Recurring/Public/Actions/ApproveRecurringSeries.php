<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Recurring\Public\Events\RecurringSeriesApproved;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/recurring/architecture.md
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

        if ($series->state === RecurringSeriesState::Approved->value) {
            return;
        }

        $this->stateMachine->transition($series, RecurringSeriesState::Approved->value, 'user_action', 'user');

        $this->events->dispatch(new RecurringSeriesApproved(
            seriesId: $series->id,
            userId: $user->id,
        ));
    }
}
