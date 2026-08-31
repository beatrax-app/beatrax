<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Exceptions\BackupIoException;
use SQLite3;

// Replacing a WAL database means replacing the `-wal` beside it, and no restore
// owns the last handle to unlink it. Both restore paths reach this one seam,
// because both met that separately and only one had finished learning it.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-restore-that-reports-success-over-a-write-ahead-log-it-did-not-remove
 */
final readonly class LiveDatabaseTransplant
{
    public function __construct(
        private DatabaseManager $db,
        private Filesystem $files,
    ) {}

    /**
     * @param  string  $undoHint  the pre-restore snapshot path, named in the failure so the operator has their undo
     *
     * @throws BackupIoException when the pages cannot be written into the live database
     */
    public function __invoke(string $sourcePath, string $livePath, string $undoHint): void
    {
        $this->purgeEveryConnectionTo($livePath);
        $this->write($sourcePath, $livePath, $undoHint);
        $this->purgeEveryConnectionTo($livePath);
    }

    // The backup API copies pages through SQLite, so the WAL and -shm
    // bookkeeping stays coherent and nothing is unlinked under a process
    // holding it mapped. The file copy remains only for a runtime without the
    // sqlite3 extension: it is the path that poisoned the phone.
    private function write(string $sourcePath, string $livePath, string $undoHint): void
    {
        if (class_exists(SQLite3::class)) {
            $this->backupInto($sourcePath, $livePath, $undoHint);

            return;
        }

        if ($this->files->copy($sourcePath, $livePath) === false) {
            throw new BackupIoException('Restore copy failed; the pre-restore snapshot is at '.$undoHint.'.');
        }

        $this->files->delete([$livePath.'-wal', $livePath.'-shm']);
    }

    private function backupInto(string $sourcePath, string $livePath, string $undoHint): void
    {
        $source = new SQLite3($sourcePath, SQLITE3_OPEN_READONLY);

        try {
            $destination = new SQLite3($livePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

            try {
                if (! $source->backup($destination)) {
                    throw new BackupIoException('Restore could not write the backup into the live database; the pre-restore snapshot is at '.$undoHint.'.');
                }
            } finally {
                $destination->close();
            }
        } finally {
            $source->close();
        }
    }

    // Laravel resolves a connection per NAME, and more than one name can point
    // at the same file -- this app has had `sqlite` and the platform default
    // both open at once. Purging by config name alone left the others holding
    // the file.
    private function purgeEveryConnectionTo(string $livePath): void
    {
        $target = realpath($livePath);

        foreach ($this->db->getConnections() as $name => $open) {
            $configured = $open->getConfig('database');

            if (! is_string($configured)) {
                continue;
            }

            $resolved = realpath($configured);

            if ($configured === $livePath || ($target !== false && $resolved === $target)) {
                $this->db->purge($name);
            }
        }
    }
}
