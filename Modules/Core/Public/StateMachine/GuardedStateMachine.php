<?php

declare(strict_types=1);

namespace Modules\Core\Public\StateMachine;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\TransitionActor;
use Throwable;

// The shared guard for a row-backed state machine: the one legal mutator that
// validates the actor and the from->to edge, flips the row's state and stamps
// a history row inside a locked transaction. The alert/series machines differ
// only in table, state graph and exceptions (the hooks) — not this algorithm.
/**
 * @link ../../../../.docs/features/core/architecture.md
 */
abstract class GuardedStateMachine
{
    use CoercesScalars;

    public function __construct(
        protected readonly DatabaseManager $db,
        protected readonly Clock $clock,
    ) {}

    // The per-state allowed target states; a from->to edge absent here is
    // rejected. Concrete machines build this from their lifecycle enum via
    // transitionMap() rather than re-spelling the projection.
    /** @return array<string, list<string>> */
    abstract protected function allowedTransitions(): array;

    // Projects a backed-enum lifecycle (whose cases each declare their own
    // successors) into the string map transitionRow() guards against, so the
    // cases-to-string-map fold lives here once instead of in every machine.
    /**
     * @template TState of \BackedEnum
     *
     * @param  list<TState>  $cases
     * @param  callable(TState): list<TState>  $next
     * @return array<string, list<string>>
     */
    protected function transitionMap(array $cases, callable $next): array
    {
        $map = [];
        foreach ($cases as $case) {
            $map[(string) $case->value] = array_map(
                static fn (\BackedEnum $target): string => (string) $target->value,
                $next($case),
            );
        }

        return $map;
    }

    abstract protected function table(): string;

    abstract protected function historyTable(): string;

    abstract protected function historyForeignKey(): string;

    abstract protected function notFound(int $id): Throwable;

    // Short machine name for the "unknown actor" rejection message — the only
    // place a concrete machine's own identity surfaces in the shared algorithm.
    abstract protected function label(): string;

    // The one algorithm every row-backed machine shares: validate the actor,
    // then within a locked transaction re-read the row, guard the edge, flip
    // state (plus any caller extraColumns), and append the history row.
    /**
     * @param  array<string, scalar|null>  $extraColumns  patched onto the same
     *                                                    row inside the transaction; `state`/`updated_at` are reserved.
     */
    protected function transitionRow(
        int $id,
        string $toState,
        string $reason,
        string $actor,
        ?string $notes,
        array $extraColumns,
    ): void {
        if (TransitionActor::tryFrom($actor) === null) {
            throw new InvalidArgumentException(
                $this->label().": unknown actor '{$actor}'; expected one of: ".implode(', ', array_column(TransitionActor::cases(), 'value')).'.',
            );
        }

        $this->db->connection()->transaction(function () use ($id, $toState, $reason, $actor, $notes, $extraColumns): void {
            $connection = $this->db->connection();
            $connection->statement('PRAGMA busy_timeout = 5000');

            $row = $connection->table($this->table())
                ->where('id', $id)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw $this->notFound($id);
            }

            $currentState = self::toString($row->state);
            $allowed = $this->allowedTransitions()[$currentState] ?? [];
            if (! in_array($toState, $allowed, strict: true)) {
                throw InvalidStateTransitionException::forTransition($this->table(), $id, $currentState, $toState);
            }

            $now = $this->clock->now()->toDateTimeString();

            $connection->table($this->table())
                ->where('id', $id)
                ->update(array_merge($extraColumns, [
                    'state' => $toState,
                    'updated_at' => $now,
                ]));

            $connection->table($this->historyTable())->insert([
                'user_id' => self::toIntOrNull($row->user_id),
                $this->historyForeignKey() => $id,
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

    // An id of 0, negative, or non-numeric is never a real user; degrade to
    // null on the audit-row FK rather than throwing, so a corrupted source
    // row never blocks the transition itself.
    private static function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
