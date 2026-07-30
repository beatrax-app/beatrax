<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use RuntimeException;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
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
        $default = $this->config->get('database.default');
        $livePath = $this->config->get('database.connections.sqlite.database');
        if ($default !== 'sqlite' || ! is_string($livePath) || $livePath === '') {
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
            $snapshotPath = $this->snapshotCurrent();

            // 4. Swap: drop the live connection, copy the verified file over the
            //    live path, and clear the stale WAL/SHM sidecars.
            $this->db->purge('sqlite');
            if ($this->files->copy($decryptedPath, $livePath) === false) {
                throw new RuntimeException('Restore copy failed; the pre-restore snapshot is at '.$snapshotPath.'.');
            }
            $this->files->delete([$livePath.'-wal', $livePath.'-shm']);
            $this->db->purge('sqlite');

            return $snapshotPath;
        } finally {
            $this->files->delete($decryptedPath);
        }
    }

    private function snapshotCurrent(): string
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
        $this->db->connection('sqlite')->statement("VACUUM INTO '{$escaped}'");
        @chmod($snapshotPath, 0600);

        return $snapshotPath;
    }

    private function assertIntegrity(string $path): void
    {
        $connectionName = '_restore_verify';
        $this->config->set('database.connections.'.$connectionName, [
            'driver' => 'sqlite',
            'database' => $path,
            'foreign_key_constraints' => false,
        ]);

        try {
            $this->db->purge($connectionName);
            $result = $this->db->connection($connectionName)->scalar('PRAGMA integrity_check');
        } catch (\Throwable $e) {
            throw new BackupFormatException('The backup is not a readable database.', 0, $e);
        } finally {
            $this->db->purge($connectionName);
        }

        if ($result !== 'ok') {
            throw new RuntimeException('The backup failed its integrity check and was not restored.');
        }
    }

    private function tempPath(string $tag): string
    {
        return sys_get_temp_dir().'/beatrax-restore-'.$tag.'-'.bin2hex(random_bytes(6)).'.sqlite';
    }
}
