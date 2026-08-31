<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;

// Handles the columns whose foreign key targets their own table. Ordering
// cannot resolve these — a transfer pair references both ways, so whichever
// row lands first names one that does not exist yet and SQLite rejects it.
// They are stripped from the insert and written back once the batch is in.
final readonly class SelfReferenceDeferral
{
    // Columns whose FK targets their OWN table, named per table so the
    // stripping pass knows what to defer.
    private const array SELF_REFERENCES = [
        'transactions' => ['pair_transaction_id'],
        'categories' => ['parent_id'],
    ];

    public function __construct(
        private DatabaseManager $db,
        private RowOwnership $ownership,
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

    // Re-applies the deferred columns now that every row in the batch is
    // present. A target still missing (its create was quarantined, or it
    // lands in a later batch) leaves the column null rather than failing
    // the row that points at it.
    /**
     * @param  list<array{table: string, pk: int|string, values: array<string, mixed>}>  $deferred
     */
    public function apply(array $deferred, int $userId): void
    {
        foreach ($deferred as $row) {
            $resolvable = $this->resolvableTargets($row['table'], $row['values'], $userId);

            if ($resolvable === []) {
                continue;
            }

            $query = $this->db->connection()->table($row['table'])->where('id', $row['pk']);

            try {
                $this->ownership->scopeToUser($query, $row['table'], $userId)->update($resolvable);
            } catch (QueryException) {
                // The link is optional by construction — the row is already
                // applied and usable without it, so a refusal here costs the
                // pairing and never the replay.
            }
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
