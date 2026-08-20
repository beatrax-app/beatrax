<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\StateMachines;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Anomaly\Public\Enums\AnomalyAlertState;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\StateMachine\GuardedStateMachine;
use Modules\Sync\Public\Events\EntityMutated;
use Throwable;

// The only legal mutator of `anomaly_alerts.state`, enforced at three layers:
// an arch test, the allowedTransitions() runtime guard, and a SQLite trigger
// pair that ABORTs on out-of-enum values.
final class AnomalyAlertStateMachine extends GuardedStateMachine
{
    use CoercesScalars;

    /**
     * @param  array<string, scalar|null>  $extraColumns  optional metric-style
     *                                                    patch applied to the same anomaly_alerts row within the
     *                                                    transition's transaction (e.g. snooze moves `snoozed_until`,
     *                                                    dismiss sets `dismissed_as`); `state`/`updated_at` reserved.
     */
    public function __construct(
        DatabaseManager $db,
        Clock $clock,
        private readonly Dispatcher $events,
    ) {
        parent::__construct($db, $clock);
    }

    public function transition(
        AnomalyAlert $alert,
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
    private function capture(AnomalyAlert $alert, int $alertId, string $toState, array $extraColumns): void
    {
        $userId = self::toInt($alert->user_id);

        // A NULL owner has no namespace to file the op under; the pairing
        // backfill skips those rows too.
        if ($userId <= 0) {
            return;
        }

        $this->events->dispatch(new EntityMutated(
            table: 'anomaly_alerts',
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
