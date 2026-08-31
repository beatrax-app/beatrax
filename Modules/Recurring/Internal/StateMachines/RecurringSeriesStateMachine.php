<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\StateMachine\GuardedStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Modules\Recurring\Public\Enums\RecurringSeriesState;
use Modules\Sync\Public\Events\EntityMutated;
use Throwable;

final class RecurringSeriesStateMachine extends GuardedStateMachine
{
    use CoercesScalars;

    public function __construct(
        DatabaseManager $db,
        Clock $clock,
        private readonly Dispatcher $events,
    ) {
        parent::__construct($db, $clock);
    }

    /**
     * @param  array<string, scalar|null>  $extraColumns  optional metric-style
     *                                                    patch applied to the same recurring_series row within the
     *                                                    transition's transaction (e.g. snooze moves `snoozed_until`)
     *                                                    atomically with the state flip; `state`/`updated_at` reserved.
     */
    public function transition(
        RecurringSeries $series,
        string $toState,
        string $reason,
        string $actor,
        ?string $notes = null,
        array $extraColumns = [],
    ): void {
        $seriesId = self::toInt($series->id);
        $this->transitionRow($seriesId, $toState, $reason, $actor, $notes, $extraColumns);

        $this->capture($series, $seriesId, $toState, $extraColumns);
    }

    // Runs after transitionRow(), never inside it: a rejected edge throws, and
    // an op for a transition the row never made would tell the peer something
    // untrue.
    /**
     * @param  array<string, scalar|null>  $extraColumns
     */
    private function capture(RecurringSeries $series, int $seriesId, string $toState, array $extraColumns): void
    {
        $userId = self::toInt($series->user_id);

        // A NULL owner has no namespace to file the op under; the pairing
        // backfill skips those rows too.
        if ($userId <= 0) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'recurring_series',
            pk: $seriesId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: array_merge($extraColumns, ['state' => $toState]),
        ));
    }

    /** @return array<string, list<string>> */
    protected function allowedTransitions(): array
    {
        return $this->transitionMap(
            RecurringSeriesState::cases(),
            static fn (RecurringSeriesState $state): array => $state->allowedNext(),
        );
    }

    protected function table(): string
    {
        return 'recurring_series';
    }

    protected function historyTable(): string
    {
        return 'recurring_series_transitions';
    }

    protected function historyForeignKey(): string
    {
        return 'recurring_series_id';
    }

    protected function label(): string
    {
        return 'RecurringSeriesStateMachine';
    }

    protected function notFound(int $id): Throwable
    {
        return SeriesRowVanishedException::forSeries($id);
    }
}
