<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\StateMachines;

use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\StateMachine\GuardedStateMachine;
use Throwable;

// The single legal mutator of `anomaly_alerts.state` and the sole inserter
// into `anomaly_alert_transitions`, enforced at three layers: arch-test static
// analysis, the allowedTransitions() runtime guard, and a SQLite trigger pair
// that ABORTs on out-of-enum values. The shared mechanics live in the base.
/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final class AnomalyAlertStateMachine extends GuardedStateMachine
{
    use CoercesScalars;

    /**
     * @param  array<string, scalar|null>  $extraColumns  optional metric-style
     *                                                    patch applied to the same anomaly_alerts row within the
     *                                                    transition's transaction (e.g. snooze moves `snoozed_until`,
     *                                                    dismiss sets `dismissed_as`); `state`/`updated_at` reserved.
     */
    public function transition(
        AnomalyAlert $alert,
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
            AnomalyAlertState::cases(),
            static fn (AnomalyAlertState $state): array => $state->allowedNext(),
        );
    }

    protected function table(): string
    {
        return 'anomaly_alerts';
    }

    protected function historyTable(): string
    {
        return 'anomaly_alert_transitions';
    }

    protected function historyForeignKey(): string
    {
        return 'anomaly_alert_id';
    }

    protected function label(): string
    {
        return 'AnomalyAlertStateMachine';
    }

    protected function notFound(int $id): Throwable
    {
        return new AnomalyAlertNotFoundException(
            "AnomalyAlertStateMachine: anomaly_alerts row {$id} not found.",
        );
    }
}
