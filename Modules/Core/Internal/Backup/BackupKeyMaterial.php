<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Sync\Public\Services\PortableKeyMaterial;
use PDO;
use PDOException;

// For a user with encryption at rest the database alone is ciphertext: the
// keys that open its sealed columns are a file beside it. So the keyring rides
// inside the snapshot, and is lifted back out before the pages reach the live
// database, which must never hold key material.
/**
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#a-backup-of-the-database-alone-is-a-backup-of-ciphertext
 */
final readonly class BackupKeyMaterial
{
    public const string TABLE = 'beatrax_backup_keyring';

    // The bytes are an authenticated-ciphertext envelope, so they survive a
    // TEXT column only base64'd; a BLOB round trip through PDO differs by
    // driver build and this file is the one that must not come back short.
    private const string CREATE_TABLE = 'CREATE TABLE '.self::TABLE.' (user_id INTEGER PRIMARY KEY, keyring TEXT NOT NULL)';

    public function __construct(
        private PortableKeyMaterial $keyMaterial,
        private Clock $clock,
    ) {}

    /**
     * @throws BackupIoException when a keyring on disk cannot be read into the snapshot
     */
    public function packInto(string $snapshotPath): void
    {
        $pdo = $this->open($snapshotPath, 'stage the backup');

        // The snapshot inherits the live file's journal mode, and a WAL write
        // here would leave the row in a -wal sidecar the encryptor never reads.
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('DROP TABLE IF EXISTS '.self::TABLE);
        $pdo->exec(self::CREATE_TABLE);

        $insert = $pdo->prepare('INSERT INTO '.self::TABLE.' (user_id, keyring) VALUES (?, ?)');
        if ($insert === false) {
            throw new BackupIoException('Cannot stage the encryption keyring into the backup snapshot.');
        }

        foreach ($this->keyMaterial->keyrings() as $userId => $path) {
            $bytes = @file_get_contents($path);
            if ($bytes === false) {
                throw new BackupIoException('Cannot read the encryption keyring the backup has to carry: '.$path);
            }

            $insert->execute([$userId, base64_encode($bytes)]);
        }
    }

    // Returns the ids whose keyring the archive carried. An archive written
    // before this table existed carries none, which is not an error: it is a
    // backup of an install that had no sealed columns to open, or one taken
    // where the keyring was expected to still be on the machine.
    /**
     * @return list<int>
     *
     * @throws BackupIoException when a carried keyring cannot be written to disk
     */
    public function unpackFrom(string $decryptedPath): array
    {
        $pdo = $this->open($decryptedPath, 'restore from the backup');

        if (! $this->carriesKeyring($pdo)) {
            return [];
        }

        $carried = $pdo->query('SELECT user_id, keyring FROM '.self::TABLE);
        if ($carried === false) {
            throw new BackupIoException('Cannot read the encryption keyring the backup carries.');
        }

        /** @var list<array{user_id: int|string, keyring: string}> $rows */
        $rows = $carried->fetchAll(PDO::FETCH_ASSOC);

        $installed = [];
        foreach ($rows as $row) {
            $bytes = base64_decode($row['keyring'], true);
            if ($bytes === false || $bytes === '') {
                throw new BackupIoException('The backup carries an encryption keyring that will not decode.');
            }

            $this->install((int) $row['user_id'], $bytes);
            $installed[] = (int) $row['user_id'];
        }

        // Dropped before the pages are written into the live database: the
        // keyring belongs beside it, never in a table any query can read.
        $pdo->exec('PRAGMA journal_mode = DELETE');
        $pdo->exec('DROP TABLE '.self::TABLE);

        return $installed;
    }

    private function carriesKeyring(PDO $pdo): bool
    {
        $statement = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?");
        if ($statement === false) {
            throw new BackupIoException('Cannot read the backup snapshot to see whether it carries a keyring.');
        }

        $statement->execute([self::TABLE]);

        return $statement->fetchColumn() !== false;
    }

    // The keyring already on this machine is moved aside rather than
    // overwritten. It may be the only copy of an epoch the incoming database
    // does not name, and the restore is not the moment to find that out.
    private function install(int $userId, string $bytes): void
    {
        $path = $this->keyMaterial->keyringPath($userId);
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new BackupIoException('Cannot create the keyring directory at '.$directory.'.');
        }

        if (is_file($path) && @rename($path, $path.'.pre-restore-'.$this->clock->now()->format('Y-m-d-His')) === false) {
            throw new BackupIoException('Cannot set aside the keyring already at '.$path.'.');
        }

        $tmp = $path.'.part';
        if (@file_put_contents($tmp, $bytes) === false || @chmod($tmp, 0600) === false || @rename($tmp, $path) === false) {
            @unlink($tmp);

            throw new BackupIoException('Cannot write the keyring the backup carries to '.$path.'.');
        }
    }

    private function open(string $path, string $what): PDO
    {
        try {
            return new PDO('sqlite:'.$path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (PDOException $e) {
            throw new BackupIoException('Cannot open the database snapshot to '.$what.': '.$path, 0, $e);
        }
    }
}
