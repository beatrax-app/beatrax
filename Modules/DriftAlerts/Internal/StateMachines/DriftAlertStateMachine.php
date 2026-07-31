<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\StateMachine\GuardedStateMachine;
use Modules\DriftAlerts\Models\DriftAlert;
use Throwable;

/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
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

    // No "any -> any" escape hatch and no same-state re-entry (idempotent
    // no-ops live in Public Actions, never here); acknowledged and
    // dismissed_cancelled are terminal (empty target arrays).
    /** @return array<string, list<string>> */
    protected function allowedTransitions(): array
    {
        return [
            'open' => ['acknowledged', 'snoozed', 'dismissed_cancelled'],
            'acknowledged' => [],
            'snoozed' => ['open', 'acknowledged', 'dismissed_cancelled'],
            'dismissed_cancelled' => [],
        ];
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
