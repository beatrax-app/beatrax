<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final readonly class UnRejectRecurringSeries
{
    public function __construct(
        private RecurringSeriesStateMachine $stateMachine,
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

        if ($series->state !== RecurringSeriesState::Rejected->value) {
            return;
        }

        $this->stateMachine->transition($series, RecurringSeriesState::Pending->value, 'user_action', 'user');
    }
}
