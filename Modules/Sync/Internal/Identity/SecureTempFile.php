<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Modules\Sync\Internal\Exceptions\SecretFileException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SecureTempFile
{
    // $path MUST live inside a directory that is itself not
    // world-traversable (0700) — this method narrows the file's own mode as
    // defense in depth; it does not fix an insecure parent directory.
    /**
     * @throws SecretFileException on a write or chmod failure.
     */
    public static function write(string $path, string $contents): void
    {
        // Suppressed so the `=== false` check decides. Unsuppressed, a failed
        // write raises E_WARNING, which Laravel's handler turns into an
        // ErrorException before the comparison runs — so this guard never
        // fired and the caller saw a type it was not looking for.
        if (@file_put_contents($path, $contents, LOCK_EX) === false) {
            throw SecretFileException::couldNotStage($path);
        }

        self::lockDown($path);
    }

    // Used after BackupEncryptor::decrypt() produces a plaintext file with no
    // permission handling of its own.
    /**
     * @throws SecretFileException on a chmod failure.
     */
    public static function lockDown(string $path): void
    {
        if (! @chmod($path, 0600)) {
            @unlink($path);

            throw SecretFileException::couldNotLockDown($path);
        }
    }
}
