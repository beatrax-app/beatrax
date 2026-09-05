<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Events;

// What a merge wrote, announced once the transaction it wrote in has committed.
// EntityMutated is the local counterpart and cannot be reused here: it is what
// the capture listener turns into an op, so raising it on arrival would
// re-author the peer's row as this device's own and send it straight back.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#what-an-arriving-row-announces
 */
final readonly class PeerRowsApplied
{
    /**
     * @param  array<string, list<int|string>>  $created  Table => the pks a CreateRow landed under.
     * @param  array<string, list<int|string>>  $updated  Table => the pks a field merge rewrote.
     * @param  array<string, list<int|string>>  $deleted  Table => the pks a tombstone removed.
     */
    public function __construct(
        public int $userId,
        public array $created = [],
        public array $updated = [],
        public array $deleted = [],
    ) {}

    /**
     * @return list<int|string>
     */
    public function deletedFrom(string $table): array
    {
        return $this->deleted[$table] ?? [];
    }

    /**
     * @param  list<string>  $tables
     */
    public function touchedAnyOf(array $tables): bool
    {
        return array_any($tables, fn (string $table): bool => isset($this->created[$table])
            || isset($this->updated[$table])
            || isset($this->deleted[$table]));
    }
}
