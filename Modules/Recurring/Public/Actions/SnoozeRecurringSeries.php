<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Support\SnoozeUntil;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// The state-machine transition carries the snoozed_until patch in its
// $extraColumns map so the state flip and the snooze timestamp land in
// the same row-locked transaction and audit row — the row is never
// observed in a "future snooze date, original state" intermediate.

final readonly class SnoozeRecurringSeries
{
    public function __construct(
        private RecurringSeriesStateMachine $stateMachine,
        private Clock $clock,
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

        // Bounded here rather than at the review page, so a tampered payload
        // cannot take a pending series out of the queue for good: the revival
        // sweep only reopens rows whose snoozed_until has passed.
        $bounded = SnoozeUntil::from($until, $this->clock->now());
        $untilString = $bounded->toDateTimeString();

        if (
            $series->state === RecurringSeriesState::Snoozed->value
            && $series->snoozed_until?->toDateTimeString() === $untilString
        ) {
            return;
        }

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
