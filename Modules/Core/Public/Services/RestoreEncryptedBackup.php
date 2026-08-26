<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Support\SqliteDatabase;
use SQLite3;
use Throwable;

final class RestoreEncryptedBackup
{
    public function __construct(
        private readonly FileEncryptor $encryptor,
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly UserDataPathService $paths,
        private readonly Clock $clock,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return string the absolute path of the pre-restore snapshot
     *
     * @throws BackupNotSupportedException when this is not the SQLite build
     * @throws BackupFormatException when the decrypted payload will not open as a
     *                               database — the live DB is only swapped after
     *                               it opens AND passes integrity_check
     */
    public function __invoke(string $encryptedPath, string $passphrase): string
    {
        $connection = SqliteDatabase::connectionName($this->config);
        $livePath = SqliteDatabase::livePath($this->config);
        if ($livePath === null) {
            throw new BackupNotSupportedException('Restore is only available on the SQLite build.');
        }

        $decryptedPath = $this->tempPath('decrypted');

        try {
            // 1. Decrypt — a wrong passphrase / tampered file throws here, before
            //    anything touches the live database.
            $this->encryptor->decrypt($encryptedPath, $decryptedPath, $passphrase);

            // 2. Prove it is a sound SQLite database before trusting it —
            //    integrity_check must return exactly ['ok'].
            $this->assertIntegrity($decryptedPath);

            // 3. Pre-restore snapshot of the CURRENT database, so the prior
            //    state is always recoverable if the swap goes wrong.
            $snapshotPath = $this->snapshotCurrent($connection);

            // 4. Write the verified pages INTO the live database, not over
            //    it. Replacing the file left a new connection running
            //    `PRAGMA journal_mode = WAL` reporting code 11, on a file
            //    whose own integrity_check passed when pulled off the device.
            $this->purgeEveryConnectionTo($livePath);
            $this->transplant($decryptedPath, $livePath, $snapshotPath);
            $this->purgeEveryConnectionTo($livePath);

            return $snapshotPath;
        } finally {
            $this->files->delete($decryptedPath);
        }
    }

    // The backup API copies pages through SQLite, so the WAL and -shm
    // bookkeeping stays coherent and nothing is unlinked under a process
    // holding it mapped. The file copy remains only for a runtime without the
    // sqlite3 extension: it is the path that poisoned the phone.
    private function transplant(string $decryptedPath, string $livePath, string $snapshotPath): void
    {
        if (class_exists(SQLite3::class)) {
            $source = new SQLite3($decryptedPath, SQLITE3_OPEN_READONLY);

            try {
                $destination = new SQLite3($livePath, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE);

                try {
                    if ($source->backup($destination) !== true) {
                        throw new BackupIoException('Restore could not write the backup into the live database; the pre-restore snapshot is at '.$snapshotPath.'.');
                    }
                } finally {
                    $destination->close();
                }
            } finally {
                $source->close();
            }

            return;
        }

        if ($this->files->copy($decryptedPath, $livePath) === false) {
            throw new BackupIoException('Restore copy failed; the pre-restore snapshot is at '.$snapshotPath.'.');
        }

        $this->files->delete([$livePath.'-wal', $livePath.'-shm']);
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

    private function snapshotCurrent(string $connection): string
    {
        // The backups directory may not exist yet (a user who has never run a
        // backup), so create it before VACUUM INTO writes the snapshot there.
        $dir = rtrim($this->paths->backups(), '/');
        $this->files->ensureDirectoryExists($dir, 0700);

        $stamp = $this->clock->now()->format('Y-m-d-His');
        $snapshotPath = $dir.'/pre-restore-'.$stamp.'.sqlite';
        $escaped = str_replace("'", "''", $snapshotPath);

        // VACUUM INTO must not run inside a transaction — SQLite refuses it,
        // so this statement runs standalone, outside any DB transaction.
        $this->db->connection($connection)->statement("VACUUM INTO '{$escaped}'");
        @chmod($snapshotPath, 0600);

        return $snapshotPath;
    }

    private function assertIntegrity(string $path): void
    {
        $connectionName = '_restore_verify';
        $this->config->set('database.connections.'.$connectionName, [
            'driver' => SqliteDatabase::DRIVER,
            'database' => $path,
            'foreign_key_constraints' => false,
        ]);

        try {
            $this->db->purge($connectionName);
            $result = $this->db->connection($connectionName)->scalar('PRAGMA integrity_check');
        } catch (Throwable $e) {
            throw new BackupFormatException('The backup is not a readable database.', 0, $e);
        } finally {
            $this->db->purge($connectionName);
        }

        if ($result !== 'ok') {
            throw new BackupFormatException('The backup failed its integrity check and was not restored.');
        }
    }

    // A 0700 directory under app storage, NEVER sys_get_temp_dir(): /tmp is
    // world-traversable at 1777, the encryptor writes through a plain fopen
    // with no chmod, and this file is the ENTIRE database in clear — it was
    // landing there at 0644 for as long as a restore took.
    private function tempPath(string $tag): string
    {
        $dir = rtrim(UserDataPathService::appPath('tmp-restore'), '/');
        $this->files->ensureDirectoryExists($dir, 0700);
        @chmod($dir, 0700);

        return $dir.'/beatrax-restore-'.$tag.'-'.bin2hex(random_bytes(6)).'.sqlite';
    }
}
