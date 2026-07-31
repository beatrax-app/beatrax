<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Models\DriftAlert;

/**
 * @link ../../../../.docs/features/drift-alerts/architecture.md
 */
final class DriftAlertStateMachine
{
    use CoercesScalars;

    // No "any state -> any state" escape hatch and no same-state re-entry
    // (idempotent no-ops live in Public Actions, never here); acknowledged
    // and dismissed_cancelled are terminal (empty target arrays).
    /** @var array<string, list<string>> */
    private const ALLOWED_TRANSITIONS = [
        'open' => ['acknowledged', 'snoozed', 'dismissed_cancelled'],
        'acknowledged' => [],
        'snoozed' => ['open', 'acknowledged', 'dismissed_cancelled'],
        'dismissed_cancelled' => [],
    ];

    /** @var list<string> */
    private const ALLOWED_ACTORS = ['user', 'detector'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, scalar|null>  $extraColumns  optional metric-style
     *                                                    patch applied to the same drift_alerts row within the
     *                                                    transition's transaction. Lets callers (snooze action) move
     *                                                    a companion column (`snoozed_until`) atomically with the
     *                                                    state flip. `state` and `updated_at` are reserved for the
     *                                                    state machine and silently overwritten if supplied.
     */
    public function transition(
        DriftAlert $alert,
        string $toState,
        string $reason,
        string $actor,
        ?string $notes = null,
        array $extraColumns = [],
    ): void {
        if (! in_array($actor, self::ALLOWED_ACTORS, strict: true)) {
            throw new InvalidArgumentException(
                "DriftAlertStateMachine: unknown actor '{$actor}'; expected one of: ".implode(', ', self::ALLOWED_ACTORS).'.',
            );
        }

        $alertId = self::toInt($alert->id);

        $this->db->connection()->transaction(function () use ($alertId, $toState, $reason, $actor, $notes, $extraColumns): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('drift_alerts')
                ->where('id', $alertId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new DriftAlertNotFoundException(
                    "DriftAlertStateMachine: drift_alerts row {$alertId} not found.",
                );
            }

            $currentState = self::toString($row->state);
            $this->guardTransition($alertId, $currentState, $toState);

            $now = $this->clock->now()->toDateTimeString();

            $update = array_merge($extraColumns, [
                'state' => $toState,
                'updated_at' => $now,
            ]);

            $connection->table('drift_alerts')
                ->where('id', $alertId)
                ->update($update);

            $userId = self::toIntOrNull($row->user_id);

            $connection->table('drift_alert_transitions')->insert([
                'user_id' => $userId,
                'drift_alert_id' => $alertId,
                'from_state' => $currentState,
                'to_state' => $toState,
                'transition_reason' => $reason,
                'actor' => $actor,
                'transitioned_at' => $now,
                'notes' => $notes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        });
    }

    private function guardTransition(int $alertId, string $currentState, string $toState): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$currentState] ?? [];
        if (! in_array($toState, $allowed, strict: true)) {
            throw InvalidStateTransitionException::forTransition($alertId, $currentState, $toState);
        }
    }

    /**
     * @return int|null a positive int, or null for a corrupted/zero/negative/non-numeric
     *                  raw drift_alerts.user_id value (see the class @link for the swallow-to-null
     *                  resilience contract)
     */
    private static function toIntOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
