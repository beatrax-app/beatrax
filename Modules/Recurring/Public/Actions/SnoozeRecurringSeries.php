<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The state-machine transition carries the snoozed_until patch in its
// $extraColumns map so the state flip and the snooze timestamp land in
// the same row-locked transaction and audit row — the row is never
// observed in a "future snooze date, original state" intermediate.

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final class SnoozeRecurringSeries
{
    public function __construct(
        private readonly RecurringSeriesStateMachine $stateMachine,
    ) {}

    public function __invoke(int $seriesId, User $user, CarbonImmutable $until): void
    {
        /** @var RecurringSeries|null $series */
        $series = RecurringSeries::query()
            ->where('id', $seriesId)
            ->where('user_id', $user->id)
            ->first();

        if ($series === null) {
            throw new NotFoundHttpException('Recurring series not found.');
        }

        if (
            $series->state === RecurringSeriesState::Snoozed->value
            && $series->snoozed_until !== null
            && $series->snoozed_until->toDateTimeString() === $until->toDateTimeString()
        ) {
            return;
        }

        $untilString = $until->toDateTimeString();

        $this->stateMachine->transition(
            $series,
            RecurringSeriesState::Snoozed->value,
            'user_action',
            'user',
            'snoozed_until='.$untilString,
            ['snoozed_until' => $untilString],
        );
    }
}
