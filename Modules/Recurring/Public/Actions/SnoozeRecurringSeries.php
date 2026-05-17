<?php

declare(strict_types=1);

namespace Modules\Recurring\Public\Actions;

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Models\User;
use Modules\Recurring\Internal\StateMachines\RecurringSeriesStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Promotes a pending / approved series to snoozed. Writes
 * `snoozed_until` directly on the row inside the same DB transaction
 * as the state-machine transition so the snooze window and the state
 * column always agree.
 *
 * Idempotent when re-snoozing to the exact same target timestamp
 * (silent no-op). Cross-user invocation raises NotFoundHttpException.
 *
 * No Public event — Phase 9 / Phase 10 listeners narrate detected /
 * approved / rejected / cadence_flipped transitions only; snooze is
 * a UI-only deferral that downstream surfaces ignore.
 */
final class SnoozeRecurringSeries
{
    public function __construct(
        private readonly DatabaseManager $db,
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

        $seriesId = $series->id;
        $untilString = $until->toDateTimeString();

        $this->db->connection()->transaction(function () use ($seriesId, $untilString): void {
            $this->db->connection()->table('recurring_series')
                ->where('id', $seriesId)
                ->update([
                    'snoozed_until' => $untilString,
                ]);
        });

        // Reload so the state machine sees the post-update row.
        /** @var RecurringSeries $fresh */
        $fresh = RecurringSeries::query()->findOrFail($seriesId);

        $this->stateMachine->transition(
            $fresh,
            'snoozed',
            'user_action',
            'user',
            'snoozed_until='.$untilString,
        );
    }
}
