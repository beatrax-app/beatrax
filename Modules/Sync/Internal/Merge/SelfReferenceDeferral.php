<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use Psr\Log\LoggerInterface;

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
        private readonly ?LoggerInterface $logger = null,
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

    // A Set names one column and carries no payload to strip, so the create
    // path's extract() cannot answer for it. Asked before the merge writes,
    // because the same foreign key refuses a Set naming an absent partner and
    // the applier's own catch would record that as a strategy error.
    public function isSelfReference(string $table, string $field): bool
    {
        return in_array($field, self::SELF_REFERENCES[$table] ?? [], true);
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

    // A backfill spanning several sync sessions gets a fresh instance for each
    // one, so the carry above cannot see a partner that landed after the last
    // session ended. Every create still says what it named, which turns the
    // repair into a query over the log rather than state to keep alive.
    public function resolveFromHistory(int $userId): int
    {
        $repaired = 0;

        foreach (self::SELF_REFERENCES as $table => $columns) {
            foreach ($columns as $column) {
                $repaired += $this->repairColumn($table, $column, $userId);
            }
        }

        return $repaired;
    }

    private function repairColumn(string $table, string $column, int $userId): int
    {
        $repaired = 0;

        try {
            // op_log_entries only ever grows, so this is streamed rather than
            // collected: the sweep costs a device that has synced for a year
            // what it costs one set up yesterday. The columns stay named
            // because cursor() takes none, and a bare stream widens the row.
            $named = $this->db->connection()->table('op_log_entries')
                ->where('user_id', $userId)
                ->where('table_name', $table)
                ->where('field', $column)
                ->whereNotNull('value')
                ->where('value', '!=', 'null')
                ->select(['pk', 'value'])
                ->orderBy('id')
                ->cursor();

            foreach ($named as $entry) {
                if ($this->repairRow($table, $column, $entry, $userId)) {
                    $repaired++;
                }
            }
        } catch (QueryException $e) {
            // A link this sweep cannot read stays null, and an unpaired
            // transfer_out is counted as money leaving the household. Returning
            // zero on its own said there was nothing to repair, which is the
            // same answer a clean log gives.
            $this->logger?->error('SelfReferenceDeferral: the log could not be read to repair a self-reference.', [
                'table' => $table,
                'column' => $column,
                'exception' => $e->getMessage(),
            ]);
        }

        return $repaired;
    }

    // Only a column still empty is filled. A row whose link is already set was
    // either resolved in its own batch or written by somebody, and neither is
    // this sweep's to overwrite.
    private function repairRow(string $table, string $column, \stdClass $entry, int $userId): bool
    {
        $target = json_decode(is_string($entry->value) ? $entry->value : '', true);
        $pk = $entry->pk;

        if ((! is_int($target) && ! is_string($target)) || (! is_int($pk) && ! is_string($pk))) {
            return false;
        }

        $empty = $this->ownership->scopeToUser(
            $this->db->connection()->table($table)->where('id', $pk)->whereNull($column),
            $table,
            $userId,
        )->exists();

        if (! $empty || $this->resolvableTargets($table, [$column => $target], $userId) === []) {
            return false;
        }

        $this->write($table, $pk, [$column => $target], $userId);

        return true;
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
