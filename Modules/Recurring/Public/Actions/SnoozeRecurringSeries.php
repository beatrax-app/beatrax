<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Carbon\CarbonImmutable;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Promotes a pending / approved series to snoozed. The state-machine
 * transition carries the `snoozed_until` patch in its $extraColumns
 * map so the state flip and the snooze timestamp land inside the
 * same row-locked transaction and the same audit row — the row can
 * never be observed in a "future snooze date, original state"
 * intermediate.
 *
 * Idempotent when re-snoozing to the exact same target timestamp
 * (silent no-op). Cross-user invocation raises NotFoundHttpException.
 *
 * No Public event — downstream listeners narrate detected / approved /
 * rejected / cadence_flipped transitions only; snooze is a UI-only
 * deferral that downstream surfaces ignore.
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
            $series->state === 'snoozed'
            && $series->snoozed_until !== null
            && $series->snoozed_until->toDateTimeString() === $until->toDateTimeString()
        ) {
            return;
        }

        $untilString = $until->toDateTimeString();

        $this->stateMachine->transition(
            $series,
            'snoozed',
            'user_action',
            'user',
            'snoozed_until='.$untilString,
            ['snoozed_until' => $untilString],
        );
    }
}
