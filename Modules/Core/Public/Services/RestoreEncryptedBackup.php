<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Backup\BackupContentsUnreadableException;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Internal\Backup\LiveDatabaseTransplant;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Core\Public\Support\SqliteDatabase;
use Throwable;

final readonly class RestoreEncryptedBackup
{
    public function __construct(
        private FileEncryptor $encryptor,
        private DatabaseManager $db,
        private Repository $config,
        private UserDataPathService $paths,
        private Clock $clock,
        private Filesystem $files,
        private BackupKeyMaterial $keyMaterial,
        private LiveDatabaseTransplant $transplant,
        private OwnerOnlyPath $ownerOnly,
    ) {}

    /**
     * @return string the absolute path of the pre-restore snapshot
     *
     * @throws BackupNotSupportedException when this is not the SQLite build
     * @throws BackupContentsUnreadableException when the decrypted payload will not
     *                                           open as a database — the live DB is
     *                                           only swapped after it opens AND
     *                                           passes integrity_check
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

            // 4. Lift the encryption keyring the archive carries out onto this
            //    machine, BEFORE the swap, so a failure here leaves the live
            //    database untouched. Restoring the rows without it hands the
            //    reader a ledger of ciphertext and calls the restore a success.
            $this->keyMaterial->unpackFrom($decryptedPath);

            // 5. Write the verified pages INTO the live database, not over
            //    it. Replacing the file left a new connection running
            //    `PRAGMA journal_mode = WAL` reporting code 11, on a file
            //    whose own integrity_check passed when pulled off the device.
            ($this->transplant)($decryptedPath, $livePath, $snapshotPath);

            return $snapshotPath;
        } finally {
            $this->files->delete($decryptedPath);
        }
    }

    private function snapshotCurrent(string $connection): string
    {
        // The backups directory may not exist yet (a user who has never run a
        // backup), so create it before VACUUM INTO writes the snapshot there.
        $dir = rtrim($this->paths->backups(), '/');
        if (! $this->ownerOnly->directory($dir)) {
            throw new BackupIoException('The backups directory could not be made owner-only: '.$dir);
        }

        $stamp = $this->clock->now()->format('Y-m-d-His');
        $snapshotPath = $dir.'/pre-restore-'.$stamp.'.sqlite';
        $escaped = str_replace("'", "''", $snapshotPath);

        // VACUUM INTO must not run inside a transaction — SQLite refuses it,
        // so this statement runs standalone, outside any DB transaction.
        $this->db->connection($connection)->statement("VACUUM INTO '{$escaped}'");

        // The whole database in clear, so a mode that will not settle is a
        // refused restore rather than a readable copy left in the backups
        // directory: VACUUM INTO creates its target at the process umask.
        if (! $this->ownerOnly->file($snapshotPath)) {
            throw new BackupIoException('The pre-restore snapshot could not be made owner-only: '.$snapshotPath);
        }

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
            throw new BackupContentsUnreadableException('The backup is not a readable database.', 0, $e);
        } finally {
            $this->db->purge($connectionName);
        }

        if ($result !== 'ok') {
            throw new BackupContentsUnreadableException('The backup failed its integrity check and was not restored.');
        }
    }

    // A 0700 directory under app storage, NEVER sys_get_temp_dir(): /tmp is
    // world-traversable at 1777, the encryptor writes through a plain fopen
    // with no chmod, and this file is the ENTIRE database in clear — it was
    // landing there at 0644 for as long as a restore took.
    private function tempPath(string $tag): string
    {
        $dir = rtrim(UserDataPathService::appPath('tmp-restore'), '/');
        if (! $this->ownerOnly->directory($dir)) {
            throw new BackupIoException('The restore staging directory could not be made owner-only: '.$dir);
        }

        return $dir.'/beatrax-restore-'.$tag.'-'.bin2hex(random_bytes(6)).'.sqlite';
    }
}
