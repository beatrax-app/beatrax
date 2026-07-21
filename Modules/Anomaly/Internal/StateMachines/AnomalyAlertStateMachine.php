<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Core\Public\Contracts\Clock;
use RuntimeException;

// The single legal mutator of `anomaly_alerts.state` and the sole
// inserter into `anomaly_alert_transitions`, enforced at three layers:
// arch-test static analysis, this class's ALLOWED_TRANSITIONS runtime
// guard, and a SQLite trigger pair that ABORTs on out-of-enum values.
/**
 * @link ../../../../.docs/features/anomaly/architecture.md
 */
final class AnomalyAlertStateMachine
{
    // A transition not present in this map raises
    // InvalidStateTransitionException — there is no "any state -> any
    // state" escape hatch. `dismissed => [open]` is the one diverging
    // undo edge: a user who dismissed an anomaly "as expected" can re-open it.
    /**
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'open' => ['acknowledged', 'snoozed', 'dismissed'],
        'acknowledged' => [],
        'snoozed' => ['open', 'acknowledged', 'dismissed'],
        'dismissed' => ['open'],
    ];

    /** @var list<string> */
    private const ALLOWED_ACTORS = ['user', 'detector'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, scalar|null>  $extraColumns  optional metric-style
     *                                                    patch applied to the same anomaly_alerts row within the
     *                                                    transition's transaction. Lets callers (snooze action move
     *                                                    `snoozed_until`, dismiss action set `dismissed_as`)
     *                                                    atomically alongside the state flip. `state` and
     *                                                    `updated_at` are reserved for the state machine and silently
     *                                                    overwritten if supplied.
     */
    public function transition(
        AnomalyAlert $alert,
        string $toState,
        string $reason,
        string $actor,
        ?string $notes = null,
        array $extraColumns = [],
    ): void {
        if (! in_array($actor, self::ALLOWED_ACTORS, strict: true)) {
            throw new InvalidArgumentException(
                "AnomalyAlertStateMachine: unknown actor '{$actor}'; expected one of: ".implode(', ', self::ALLOWED_ACTORS).'.',
            );
        }

        $alertId = self::toInt($alert->id);

        $this->db->connection()->transaction(function () use ($alertId, $toState, $reason, $actor, $notes, $extraColumns): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('anomaly_alerts')
                ->where('id', $alertId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "AnomalyAlertStateMachine: anomaly_alerts row {$alertId} not found.",
                );
            }

            $currentState = self::toString($row->state);
            $this->guardTransition($alertId, $currentState, $toState);

            $now = $this->clock->now()->toDateTimeString();

            $update = array_merge($extraColumns, [
                'state' => $toState,
                'updated_at' => $now,
            ]);

            $connection->table('anomaly_alerts')
                ->where('id', $alertId)
                ->update($update);

            $userId = self::toIntOrNull($row->user_id);

            $connection->table('anomaly_alert_transitions')->insert([
                'user_id' => $userId,
                'anomaly_alert_id' => $alertId,
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    // An id of 0, negative, or non-numeric is never a real user; this
    // silently degrades to null on the audit-row FK rather than throwing,
    // so a corrupted source row never blocks the state transition itself.
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

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
