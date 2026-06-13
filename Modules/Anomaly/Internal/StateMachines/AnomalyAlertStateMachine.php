<?php

declare(strict_types=1);

namespace Modules\Anomaly\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Anomaly\Models\AnomalyAlert;
use Modules\Core\Public\Contracts\Clock;
use RuntimeException;

/**
 * The single legal mutator of `anomaly_alerts.state` and the sole
 * inserter into `anomaly_alert_transitions`. Other module code reads the
 * row and may UPDATE non-state columns (the evaluator refreshes
 * latest_amount_minor / detected_at on a revival flip; the snooze action
 * sets snoozed_until alongside the state flip via `$extraColumns`; the
 * dismiss action patches `dismissed_as` the same way).
 *
 * The schema-level `anomaly_alerts_state_check_insert/update` trigger
 * pair plus the `noOtherAnomalyAlertStateMutator` BoundaryArchTest
 * invariant enforce the sole-mutator contract at three layers:
 *   1. Static-analysis (arch test rejects any non-allowed file that
 *      writes to anomaly_alerts.state).
 *   2. Runtime (this class's ALLOWED_TRANSITIONS map throws
 *      InvalidStateTransitionException on illegal targets).
 *   3. Database (SQLite triggers ABORT on out-of-enum state values even
 *      when an arbitrary code path bypasses this class).
 *
 * Public surface mirrors DriftAlertStateMachine: a single `transition()`
 * method that opens a transaction, sets `PRAGMA busy_timeout = 5000`,
 * takes a row lock, validates against `ALLOWED_TRANSITIONS`, writes the
 * new state + updated_at, and inserts exactly one
 * anomaly_alert_transitions row carrying the full audit metadata. Throws
 * `InvalidStateTransitionException` for an illegal target,
 * `InvalidArgumentException` for an unknown actor, and `RuntimeException`
 * when the alert row is missing.
 *
 * SQLite contention guard: every transition opens a transaction, sets
 * `PRAGMA busy_timeout = 5000`, and reads the row via `lockForUpdate()`.
 * Two concurrent detectors that briefly contend on the same alert row
 * therefore serialise rather than fail.
 */
final class AnomalyAlertStateMachine
{
    /**
     * Per-state allowed-target map. A transition not present in this map
     * raises `InvalidStateTransitionException` — there is no "any state →
     * any state" escape hatch and no same-state re-entry (idempotent
     * no-ops live in Public Actions, never here). `acknowledged` is
     * terminal (empty target array).
     *
     * DIVERGES from drift: `dismissed => [open]` is the undo edge
     * (D-18 / RESEARCH A7) — a user who dismissed an anomaly "as
     * expected" can re-open it. `dismissed -> anything-else` stays
     * rejected.
     *
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

    /**
     * Coerces a raw anomaly_alerts.user_id value into either a positive
     * int or null.
     *
     * SQLite users.id is an autoincrementing surrogate starting at 1; an
     * id of 0 (or negative, or non-numeric) is never a real user. The
     * state machine silently degrades any of those shapes to null on the
     * audit-row FK so the transition contract — "write exactly one
     * anomaly_alert_transitions row per legal state flip" — stays
     * resilient against a corrupted source row. Callers that need to
     * detect the corruption can inspect the resulting
     * anomaly_alert_transitions.user_id IS NULL row.
     *
     * Locked by `AnomalyAlertStateMachineTest` so a future refactor
     * cannot quietly change the swallow-to-null semantics into a throw
     * without updating the test.
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

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
