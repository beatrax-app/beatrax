<?php

declare(strict_types=1);

namespace Modules\Core\Public\StateMachine;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Concerns\CoercesScalars;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\TransitionActor;
use Throwable;

// The alert and series machines differ only in table, state graph and
// exceptions — the transition algorithm itself lives here, once.
abstract class GuardedStateMachine
{
    use CoercesScalars;

    public function __construct(
        protected readonly DatabaseManager $db,
        protected readonly Clock $clock,
    ) {}

    // Per-state allowed targets; an edge absent here is rejected. Build it from
    // the lifecycle enum via transitionMap(), never by re-spelling the map.
    /** @return array<string, list<string>> */
    abstract protected function allowedTransitions(): array;

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

    // Short machine name for the "unknown actor" rejection message.
    abstract protected function label(): string;

    // The row is re-read under lockForUpdate INSIDE the transaction, so a
    // concurrent transition cannot slip between the edge guard and the write.
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

    // A 0, negative or non-numeric id is never a real user: degrade the audit
    // row's foreign key to null rather than blocking the transition.
    private static function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || ! is_numeric($value)) {
            return null;
        }
        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
