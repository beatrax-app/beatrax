<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\StateMachine\GuardedStateMachine;
use Modules\Recurring\Models\RecurringSeries;
use Throwable;

/**
 * @link ../../../../.docs/features/recurring/architecture.md
 */
final class RecurringSeriesStateMachine extends GuardedStateMachine
{
    use CoercesScalars;

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
        $this->transitionRow(self::toInt($series->id), $toState, $reason, $actor, $notes, $extraColumns);
    }

    // No "any -> any" escape hatch and no same-state re-entry (idempotent
    // no-ops live in Public Actions, never here).
    /** @return array<string, list<string>> */
    protected function allowedTransitions(): array
    {
        return [
            'pending' => ['approved', 'rejected', 'snoozed'],
            'approved' => ['cadence_changed', 'rejected'],
            'cadence_changed' => ['approved', 'rejected'],
            'snoozed' => ['pending', 'approved', 'rejected'],
            'rejected' => ['pending'],
        ];
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
