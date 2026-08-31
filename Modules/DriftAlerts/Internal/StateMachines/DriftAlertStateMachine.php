<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\StateMachine\GuardedStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Modules\Sync\Public\Events\EntityMutated;
use Throwable;

final class DriftAlertStateMachine extends GuardedStateMachine
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
     *                                                    patch applied to the same drift_alerts row within the
     *                                                    transition's transaction (e.g. snooze moves `snoozed_until`)
     *                                                    atomically with the state flip; `state`/`updated_at` reserved.
     */
    public function transition(
        DriftAlert $alert,
        string $toState,
        string $reason,
        string $actor,
        ?string $notes = null,
        array $extraColumns = [],
    ): void {
        $alertId = self::toInt($alert->id);
        $this->transitionRow($alertId, $toState, $reason, $actor, $notes, $extraColumns);

        $this->capture($alert, $alertId, $toState, $extraColumns);
    }

    // Runs after transitionRow(), never inside it: a rejected edge throws, and
    // an op for a transition the row never made would tell the peer something
    // untrue.
    /**
     * @param  array<string, scalar|null>  $extraColumns
     */
    private function capture(DriftAlert $alert, int $alertId, string $toState, array $extraColumns): void
    {
        $userId = self::toInt($alert->user_id);

        // A NULL owner has no namespace to file the op under; the pairing
        // backfill skips those rows too.
        if ($userId <= 0) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'drift_alerts',
            pk: $alertId,
            userId: $userId,
            mutationType: 'edit',
            dirtyFields: array_merge($extraColumns, ['state' => $toState]),
        ));
    }

    /** @return array<string, list<string>> */
    protected function allowedTransitions(): array
    {
        return $this->transitionMap(
            DriftAlertState::cases(),
            static fn (DriftAlertState $state): array => $state->allowedNext(),
        );
    }

    protected function table(): string
    {
        return 'drift_alerts';
    }

    protected function historyTable(): string
    {
        return 'drift_alert_transitions';
    }

    protected function historyForeignKey(): string
    {
        return 'drift_alert_id';
    }

    protected function label(): string
    {
        return 'DriftAlertStateMachine';
    }

    protected function notFound(int $id): Throwable
    {
        return new DriftAlertNotFoundException(
            "DriftAlertStateMachine: drift_alerts row {$id} not found.",
        );
    }
}
