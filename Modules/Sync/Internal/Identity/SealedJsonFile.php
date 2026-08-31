<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupDecryptionException;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Support\SecretFileMode;
use Modules\Sync\Internal\Exceptions\SecretFileException;

// A whole-file secret encrypted at rest, read and written through one staging
// recipe. Every step is load-bearing — stage inside the sanctioned 0700
// directory, narrow to 0600 before reading, unlink whatever happens — and a
// recipe whose point is that every step runs must not be retyped per caller.
/**
 * @link ../../../../.docs/features/sync/device-identity-key-files.md
 */
final readonly class SealedJsonFile
{
    public function __construct(private FileEncryptor $encryptor) {}

    // Never sys_get_temp_dir(): /tmp is world-traversable at mode 1777, so a
    // decrypted key-file staged there is readable by any local account for as
    // long as it exists.
    /**
     * @param  string  $tmpPrefix  Names the staging file after what it holds.
     *
     * @throws SecretFileException when the plaintext cannot be locked down or read.
     * @throws BackupFormatException when the sealed file is not one of ours
     * @throws BackupDecryptionException on a wrong KEK, tampering, or truncation
     * @throws BackupIoException on I/O failure inside the decryptor
     */
    public function readPlaintext(string $sealedPath, string $kek, string $tmpPrefix): string
    {
        $tmpPath = $this->stagingPath($sealedPath, $tmpPrefix);

        try {
            $this->encryptor->decrypt($sealedPath, $tmpPath, $kek);

            // The encryptor renames its own staging file onto $tmpPath, which
            // lands at the process umask default — narrow it before the first
            // plaintext byte is read back out.
            SecureTempFile::lockDown($tmpPath);

            // Suppressed so the `=== false` check decides. Unsuppressed, a
            // failed read raises E_WARNING, which Laravel's handler turns into
            // an ErrorException before the comparison runs.
            $plaintext = @file_get_contents($tmpPath);
            if ($plaintext === false) {
                throw SecretFileException::couldNotReadStagedPlaintext($tmpPath);
            }

            return $plaintext;
        } finally {
            @unlink($tmpPath);
        }
    }

    /**
     * @throws SecretFileException when the plaintext cannot be staged or locked down.
     */
    public function writeSealed(string $sealedPath, string $plaintext, string $kek, string $tmpPrefix): void
    {
        $this->sealTo($sealedPath, $sealedPath, $plaintext, $kek, $tmpPrefix);
    }

    // Seals to a randomized `.tmp` sibling WITHOUT renaming it into place, for
    // the one caller that finalizes later. Randomized because a fixed name let
    // two concurrent writes, or a stale file from a crashed run, collide.
    /**
     * @return string The sealed staging path, for the caller to rename.
     *
     * @throws SecretFileException when the plaintext cannot be staged or locked down.
     */
    public function stageSealed(string $sealedPath, string $plaintext, string $kek, string $tmpPrefix): string
    {
        $sealedTmpPath = $sealedPath.'.'.bin2hex(random_bytes(8)).'.tmp';

        $this->sealTo($sealedPath, $sealedTmpPath, $plaintext, $kek, $tmpPrefix);

        return $sealedTmpPath;
    }

    private function sealTo(
        string $sealedPath,
        string $destination,
        string $plaintext,
        string $kek,
        string $tmpPrefix,
    ): void {
        $tmpPath = $this->stagingPath($sealedPath, $tmpPrefix);

        // Locked to 0600 by SecureTempFile::write, which throws rather than
        // leaving the plaintext readable, so a crash before the unlink below
        // can never strand a world-readable secret.
        SecureTempFile::write($tmpPath, $plaintext);

        try {
            // The KEK is 256 random bits rather than a passphrase, so the
            // password-hardening cost buys nothing and made every read ~500ms.
            $this->encryptor->encryptWithKey($tmpPath, $destination, $kek);
        } finally {
            @unlink($tmpPath);
        }
    }

    private function stagingPath(string $sealedPath, string $tmpPrefix): string
    {
        $directory = dirname($sealedPath);
        @mkdir($directory, SecretFileMode::DIRECTORY, true);

        return $directory.DIRECTORY_SEPARATOR.$tmpPrefix.bin2hex(random_bytes(8)).'.tmp';
    }
}
