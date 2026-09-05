<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use Illuminate\Database\DatabaseManager;
use Modules\Core\Internal\Storage\UserDataLocations;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Throwable;

/**
 * @link ../../../../.docs/features/core/one-export-action.md
 */
final readonly class ExportEverythingArchive
{
    public function __construct(
        private DatabaseManager $db,
        private FileEncryptor $encryptor,
        private BackupKeyMaterial $keyMaterial,
        private OwnerOnlyPath $ownerOnly,
        private ArchiveWriterFactory $writers,
    ) {}

    // The database goes in encrypted and the source documents go in as they
    // are: they are the reader's own files, already sitting unencrypted in the
    // folders this archive copies, and a reader who cannot open their own
    // receipts has not been given an export.
    /**
     * @throws BackupIoException
     */
    public function build(string $passphrase, string $stamp): string
    {
        $staging = UserDataPathService::appPath('tmp-backups');
        if (! $this->ownerOnly->directory($staging)) {
            throw new BackupIoException('The staging directory could not be made owner-only.');
        }

        $suffix = $stamp.'-'.bin2hex(random_bytes(4));
        $encrypted = $this->encryptedSnapshot($staging, $suffix, $passphrase);
        $zipPath = $staging.DIRECTORY_SEPARATOR.'beatrax-export-'.$suffix.'.zip';

        try {
            $this->pack($zipPath, $encrypted, ExportArchiveBackup::ENTRY_PREFIX.$stamp.ExportArchiveBackup::ENTRY_SUFFIX);
        } catch (Throwable $e) {
            @unlink($zipPath);

            throw $e;
        } finally {
            @unlink($encrypted);
        }

        if (! $this->ownerOnly->file($zipPath)) {
            @unlink($zipPath);

            throw new BackupIoException('The export archive could not be made owner-only.');
        }

        return $zipPath;
    }

    // VACUUM INTO must not run in a transaction and refuses an existing target.
    // The key material is packed in before the encrypt because a snapshot that
    // leaves without it is a copy of ciphertext for anyone whose columns are
    // sealed.
    /**
     * @throws BackupIoException
     */
    private function encryptedSnapshot(string $staging, string $suffix, string $passphrase): string
    {
        $plain = $staging.DIRECTORY_SEPARATOR.'beatrax-export-'.$suffix.'.sqlite';
        $encrypted = $plain.'.enc';

        try {
            $escaped = str_replace("'", "''", $plain);
            $this->db->connection()->statement("VACUUM INTO '{$escaped}'");
            if (! is_file($plain)) {
                throw new BackupIoException('The database snapshot was not produced.');
            }
            if (! $this->ownerOnly->file($plain)) {
                throw new BackupIoException('The database snapshot could not be made owner-only.');
            }

            $this->keyMaterial->packInto($plain);
            $this->encryptor->encrypt($plain, $encrypted, $passphrase);
        } catch (Throwable $e) {
            @unlink($encrypted);

            throw $e;
        } finally {
            @unlink($plain);
        }

        return $encrypted;
    }

    // The backup goes in first, before any source document, so it is the entry
    // a restore finds at the head of the archive without walking it.
    /**
     * @throws BackupIoException
     */
    private function pack(string $zipPath, string $encrypted, string $backupEntry): void
    {
        $writer = $this->writers->make();
        $writer->open($zipPath);
        $writer->addFile($encrypted, $backupEntry);

        foreach (UserDataLocations::artefacts() as $key => $directory) {
            $this->addDirectory($writer, $directory, 'artefacts/'.$key);
        }

        $writer->finish();
    }

    // An artefact directory the reader never populated simply is not there, and
    // an export that refused over a missing folder would be an export nobody
    // with only bank imports could ever take.
    private function addDirectory(ArchiveWriter $writer, string $directory, string $entryPrefix): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $entry */
        foreach ($walk as $entry) {
            if (! $entry->isFile() || $entry->isLink()) {
                continue;
            }

            $relative = substr($entry->getPathname(), strlen($directory) + 1);
            $writer->addFile($entry->getPathname(), $entryPrefix.'/'.str_replace(DIRECTORY_SEPARATOR, '/', $relative));
        }
    }
}
