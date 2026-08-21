<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\StateMachine\GuardedStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Modules\DriftAlerts\Public\Enums\DriftAlertState;
use Throwable;

final class DriftAlertStateMachine extends GuardedStateMachine
{
    use CoercesScalars;

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
        $this->transitionRow(self::toInt($alert->id), $toState, $reason, $actor, $notes, $extraColumns);
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
