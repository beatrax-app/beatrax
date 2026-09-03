<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

// Handles the columns whose foreign key targets their own table. Ordering
// cannot resolve these — a transfer pair references both ways, so whichever
// row lands first names one that does not exist yet and SQLite rejects it.
// They are stripped from the insert and written back once the target is here.
/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SelfReferenceDeferral
{
    // Columns whose FK targets their OWN table, named per table so the
    // stripping pass knows what to defer.
    private const array SELF_REFERENCES = [
        'transactions' => ['pair_transaction_id'],
        'categories' => ['parent_id'],
    ];

    // A backfill is replayed in batches and nothing makes a transfer pair land
    // in one of them, so a link unresolved at the end of a batch is held for
    // the next. Bounded because a target that never arrives never resolves,
    // and the oldest have had the most chances already.
    private const int MAX_PENDING = 10000;

    /** @var list<array{table: string, pk: int|string, values: array<string, mixed>}> */
    private array $pending = [];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly RowOwnership $ownership,
    ) {}

    // Strips the self-referential columns out of an insert payload, nulling
    // them in place and handing back what was removed.
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extract(string $table, array &$payload): array
    {
        $extracted = [];

        foreach (self::SELF_REFERENCES[$table] ?? [] as $column) {
            $value = $payload[$column] ?? null;

            if ($value === null) {
                continue;
            }

            $extracted[$column] = $value;
            $payload[$column] = null;
        }

        return $extracted;
    }

    // Re-applies the deferred columns now that their targets are present, and
    // carries whatever is still unsatisfied into the next batch. Dropping it
    // there cost a first sync every transfer pair whose partner sat further
    // down the log — the link was never retried once the partner landed.
    /**
     * @param  list<array{table: string, pk: int|string, values: array<string, mixed>}>  $deferred
     */
    public function apply(array $deferred, int $userId): void
    {
        $this->pending = array_slice([...$this->pending, ...$deferred], -self::MAX_PENDING);

        $stillWaiting = [];

        foreach ($this->pending as $row) {
            $resolvable = $this->resolvableTargets($row['table'], $row['values'], $userId);

            if ($resolvable !== []) {
                $this->write($row['table'], $row['pk'], $resolvable, $userId);
            }

            $waiting = array_diff_key($row['values'], $resolvable);

            if ($waiting !== []) {
                $stillWaiting[] = ['table' => $row['table'], 'pk' => $row['pk'], 'values' => $waiting];
            }
        }

        $this->pending = $stillWaiting;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function write(string $table, int|string $pk, array $values, int $userId): void
    {
        $query = $this->db->connection()->table($table)->where('id', $pk);

        try {
            $this->ownership->scopeToUser($query, $table, $userId)->update($values);
        } catch (QueryException) {
            // The link is optional by construction — the row is already
            // applied and usable without it, so a refusal here costs the
            // pairing and never the replay.
        }
    }

    // Only the targets that actually landed, scoped to this user so a
    // deferred link can never be resolved against another account's row.
    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function resolvableTargets(string $table, array $values, int $userId): array
    {
        $resolvable = [];

        foreach ($values as $column => $target) {
            $exists = $this->ownership->scopeToUser(
                $this->db->connection()->table($table)->where('id', $target),
                $table,
                $userId,
            )->exists();

            if ($exists) {
                $resolvable[$column] = $target;
            }
        }

        return $resolvable;
    }
}
