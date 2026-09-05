<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Merge;

use Illuminate\Database\DatabaseManager;
use Modules\Sync\Public\Events\PeerRowsApplied;

// The rows one replay actually wrote, held while the merge runs and read once
// it commits. The full-text index rebuilds from them, and the modules keeping
// derived state or a cross-row rule hear about them through PeerRowsApplied —
// the two questions a raw query-builder write answers nowhere else.
/**
 * @link ../../../../.docs/features/sync/op-log-merge-rules.md#what-an-arriving-row-announces
 */
final class ReplayedRows
{
    /** @var array<string, array<int|string, int|string>> */
    private array $created = [];

    /** @var array<string, array<int|string, int|string>> */
    private array $updated = [];

    /** @var array<string, array<int|string, int|string>> */
    private array $deleted = [];

    private readonly SearchDocumentRows $documents;

    public function __construct(DatabaseManager $db)
    {
        $this->documents = new SearchDocumentRows($db);
    }

    public function documents(): SearchDocumentRows
    {
        return $this->documents;
    }

    public function rowCreated(string $table, int|string $pk, int $userId): void
    {
        $this->created[$table][$pk] = $pk;
        $this->documents->rowWritten($table, $pk, $userId);
    }

    public function rowUpdated(string $table, int|string $pk, int $userId): void
    {
        $this->updated[$table][$pk] = $pk;
        $this->documents->rowWritten($table, $pk, $userId);
    }

    /**
     * @return list<int>
     */
    public function documentsOf(string $table, int|string $pk, int $userId): array
    {
        return $this->documents->documentsOf($table, $pk, $userId);
    }

    /**
     * @param  list<int>  $documentIds  What documentsOf() answered while the row was still here.
     */
    public function rowDeleted(string $table, int|string $pk, array $documentIds): void
    {
        $this->deleted[$table][$pk] = $pk;
        $this->documents->rowDeleted($table, $documentIds);
    }

    // Null when the merge wrote nothing, so a catch-up that applied no op does
    // not wake a listener on the arrival path to tell it so.
    public function announcement(int $userId): ?PeerRowsApplied
    {
        if ($this->created === [] && $this->updated === [] && $this->deleted === []) {
            return null;
        }

        return new PeerRowsApplied(
            userId: $userId,
            created: self::pks($this->created),
            updated: self::pks($this->updated),
            deleted: self::pks($this->deleted),
        );
    }

    // Keyed by pk while collecting, because one replay reaches the same row
    // from more than one op and a listener should hear about it once.
    /**
     * @param  array<string, array<int|string, int|string>>  $rows
     * @return array<string, list<int|string>>
     */
    private static function pks(array $rows): array
    {
        return array_map(array_values(...), $rows);
    }
}
