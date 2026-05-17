<?php

declare(strict_types=1);

namespace Modules\DriftAlerts\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\DriftAlerts\Models\DriftAlert;
use RuntimeException;

/**
 * The single legal mutator of `drift_alerts.state` and the sole
 * inserter into `drift_alert_transitions`. Other module code reads
 * the row and may UPDATE non-state columns (the evaluator refreshes
 * latest_amount_minor / annualized_impact_minor / detected_at on a
 * revival flip; the snooze action sets snoozed_until alongside the
 * state flip via `$extraColumns`).
 *
 * The schema-level `drift_alerts_state_check_insert/update` trigger
 * pair plus the `noOtherDriftAlertStateMutator` BoundaryArchTest
 * invariant enforce the sole-mutator contract at three layers:
 *   1. Static-analysis (arch test rejects any non-allowed file that
 *      writes to drift_alerts.state).
 *   2. Runtime (this class's ALLOWED_TRANSITIONS map throws
 *      InvalidStateTransitionException on illegal targets).
 *   3. Database (SQLite triggers ABORT on out-of-enum state values
 *      even when an arbitrary code path bypasses this class).
 *
 * Public surface mirrors RecurringSeriesStateMachine: a single
 * `transition()` method that opens a transaction, sets
 * `PRAGMA busy_timeout = 5000`, takes a row lock, validates against
 * `ALLOWED_TRANSITIONS`, writes the new state + updated_at, and
 * inserts exactly one drift_alert_transitions row carrying the full
 * audit metadata. Throws `InvalidStateTransitionException` for an
 * illegal target, `InvalidArgumentException` for an unknown actor,
 * and `RuntimeException` when the alert row is missing.
 *
 * SQLite contention guard: every transition opens a transaction,
 * sets `PRAGMA busy_timeout = 5000`, and reads the row via
 * `lockForUpdate()`. Two concurrent detectors that briefly contend
 * on the same alert row therefore serialise rather than fail.
 */
final class DriftAlertStateMachine
{
    /**
     * Per-state allowed-target map. A transition not present in this
     * map raises `InvalidStateTransitionException` — there is no
     * "any state → any state" escape hatch and no same-state
     * re-entry (idempotent no-ops live in Public Actions, never
     * here). `acknowledged` and `dismissed_cancelled` are terminal
     * (empty target arrays).
     *
     * @var array<string, list<string>>
     */
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
                throw new RuntimeException(
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

    private static function toInt(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function toIntOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if (! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        // SQLite users.id is an autoincrementing surrogate starting
        // at 1; an id of 0 (or negative) is never a real user. Treat
        // stringly-numeric '0' as null so the transitions audit row's
        // FK never points at a phantom user_id=0.
        return $int > 0 ? $int : null;
    }

    private static function toString(mixed $value): string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string) $value : '');
    }
}
