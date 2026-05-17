<?php

declare(strict_types=1);

namespace Modules\Recurring\Internal\StateMachines;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Recurring\Models\RecurringSeries;
use RuntimeException;

/**
 * The single legal mutator of `recurring_series.state` and the sole
 * inserter into `recurring_series_transitions`. Every other write path
 * in the Recurring module touches only non-state columns (the
 * detectors refresh latest amount, monthly equivalent, next-expected-
 * charge, funding-chain link, snoozed_until — never the state column).
 *
 * The `noOtherRecurringSeriesStateMutator` arch invariant under
 * `tests/Contracts/BoundaryArchTest.php` enforces this contract at the
 * static-analysis level; the schema-level BEFORE INSERT / BEFORE
 * UPDATE triggers on `recurring_series` enforce it at the database
 * level.
 *
 * Public surface:
 *
 *  - `transition(RecurringSeries $series, string $toState, string $reason, string $actor, ?string $notes)` —
 *    validates the requested target against ALLOWED_TRANSITIONS, opens
 *    a DB transaction, sets `PRAGMA busy_timeout = 5000`, takes a row
 *    lock on the series, writes the new state + updated_at, and
 *    inserts one row in recurring_series_transitions carrying the full
 *    audit metadata. Throws `InvalidStateTransitionException` for an
 *    illegal target, `InvalidArgumentException` for an unknown actor,
 *    and `RuntimeException` when the series row is missing.
 *
 * SQLite contention guard: every transition opens a transaction, sets
 * `PRAGMA busy_timeout = 5000`, and reads the row via
 * `lockForUpdate()`. Two concurrent sweep jobs that briefly contend on
 * the same series row therefore serialise rather than fail.
 */
final class RecurringSeriesStateMachine
{
    /**
     * Per-state allowed-target map. A transition not present in this
     * map raises InvalidStateTransitionException — there is no
     * "any state → any state" escape hatch and no same-state re-entry
     * (idempotent no-ops live in Public Actions, never here).
     *
     * @var array<string, list<string>>
     */
    private const ALLOWED_TRANSITIONS = [
        'pending' => ['approved', 'rejected', 'snoozed'],
        'approved' => ['cadence_changed', 'rejected'],
        'cadence_changed' => ['approved', 'rejected'],
        'snoozed' => ['pending', 'approved', 'rejected'],
        'rejected' => ['pending'],
    ];

    /** @var list<string> */
    private const ALLOWED_ACTORS = ['user', 'detector'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * @param  array<string, scalar|null>  $extraColumns  optional metric-style
     *                                                    patch applied to the same recurring_series row within the
     *                                                    transition's transaction. Lets callers (e.g. snooze) move a
     *                                                    companion column (`snoozed_until`) atomically with the state
     *                                                    flip. `state` and `updated_at` are reserved for the state
     *                                                    machine and silently overwritten if supplied.
     */
    public function transition(
        RecurringSeries $series,
        string $toState,
        string $reason,
        string $actor,
        ?string $notes = null,
        array $extraColumns = [],
    ): void {
        if (! in_array($actor, self::ALLOWED_ACTORS, strict: true)) {
            throw new InvalidArgumentException(
                "RecurringSeriesStateMachine: unknown actor '{$actor}'; expected one of: ".implode(', ', self::ALLOWED_ACTORS).'.',
            );
        }

        $seriesId = self::toInt($series->id);

        $this->db->connection()->transaction(function () use ($seriesId, $toState, $reason, $actor, $notes, $extraColumns): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table('recurring_series')
                ->where('id', $seriesId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw new RuntimeException(
                    "RecurringSeriesStateMachine: recurring_series row {$seriesId} not found.",
                );
            }

            $currentState = self::toString($row->state);
            $this->guardTransition($seriesId, $currentState, $toState);

            $now = $this->clock->now()->toDateTimeString();

            $update = array_merge($extraColumns, [
                'state' => $toState,
                'updated_at' => $now,
            ]);

            $connection->table('recurring_series')
                ->where('id', $seriesId)
                ->update($update);

            $userId = self::toIntOrNull($row->user_id);

            $connection->table('recurring_series_transitions')->insert([
                'user_id' => $userId,
                'recurring_series_id' => $seriesId,
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

    private function guardTransition(int $seriesId, string $currentState, string $toState): void
    {
        $allowed = self::ALLOWED_TRANSITIONS[$currentState] ?? [];
        if (! in_array($toState, $allowed, strict: true)) {
            throw InvalidStateTransitionException::forTransition($seriesId, $currentState, $toState);
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
