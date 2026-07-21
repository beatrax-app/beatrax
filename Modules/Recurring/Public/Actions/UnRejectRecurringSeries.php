<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final class UnRejectRecurringSeries
{
    public function __construct(
        private readonly RecurringSeriesStateMachine $stateMachine,
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

        if ($series->state !== 'rejected') {
            return;
        }

        $this->stateMachine->transition($series, 'pending', 'user_action', 'user');
    }
}
