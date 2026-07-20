<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Public\Contracts\Clock;
use RuntimeException;

/**
 * Restores the SQLite database from a passphrase-encrypted backup, mirroring the
 * db:restore CLI's safety rails for the in-app flow.
 *
 * Ordering is the safety contract: the upload is decrypted and integrity-checked
 * to a temp file FIRST — if the passphrase is wrong or the backup is corrupt, it
 * throws and the live database is never touched. Only once the source is proven
 * good does it take a pre-restore snapshot of the current database (so the old
 * state is always recoverable) and then atomically swap the file in. SQLite-only;
 * the caller is responsible for the destructive-action gate (a typed confirmation)
 * and for reloading the app afterwards (the live connection now points at the
 * restored file).
 */
final class RestoreEncryptedBackup
{
    public function __construct(
        private readonly BackupEncryptor $encryptor,
        private readonly DatabaseManager $db,
        private readonly Repository $config,
        private readonly UserDataPathService $paths,
        private readonly Clock $clock,
        private readonly Filesystem $files,
    ) {}

    /**
     * @return string the absolute path of the pre-restore snapshot
     *
     * @throws RuntimeException on any failure; the live DB is only swapped after
     *                          the source decrypts AND passes integrity_check.
     */
    public function __invoke(string $encryptedPath, string $passphrase): string
    {
        $default = $this->config->get('database.default');
        $livePath = $this->config->get('database.connections.sqlite.database');
        if ($default !== 'sqlite' || ! is_string($livePath) || $livePath === '') {
            throw new RuntimeException('Restore is only available on the SQLite build.');
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
            throw new RuntimeException('The backup is not a readable database.', 0, $e);
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
