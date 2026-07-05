<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use RuntimeException;

/**
 * Shared "stage plaintext secret material at 0600" helper used at the device
 * identity key-file's encrypt/decrypt boundary.
 *
 * BackupEncryptor's file-path API (Modules\Core\Public\Services\BackupEncryptor)
 * reads/writes plaintext through plain fopen() calls with no permission
 * handling of its own, so any caller staging plaintext next to it is
 * responsible for locking the file down. DeviceIdentityService (write) and
 * DeviceIdentityLoader (read) both stage a plaintext copy of the device
 * identity JSON — this class is the one place that pins those staging files
 * to 0600 and verifies the chmod actually stuck, mirroring the same
 * verify-or-throw posture already used by RelayConfig::setAuthToken() and the
 * db:backup / db:restore commands elsewhere in the codebase.
 *
 * WR-06: a silently-swallowed chmod failure would leave secret key material
 * world-readable with no signal — every method here throws (and removes the
 * file) rather than continuing past a chmod failure.
 */
final class SecureTempFile
{
    /**
     * Write $contents to $path and immediately restrict it to 0600.
     *
     * $path MUST live inside a directory that is itself not world-traversable
     * (0700) — this method narrows the file's own mode as defense in depth,
     * it does not (and cannot) fix an insecure parent directory.
     *
     * @throws RuntimeException on a write or chmod failure.
     */
    public static function write(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents, LOCK_EX) === false) {
            throw new RuntimeException("Failed to stage secret material at: {$path}");
        }

        self::lockDown($path);
    }

    /**
     * Restrict an existing file to 0600, verifying the chmod succeeded.
     * Used after BackupEncryptor::decrypt() produces a plaintext file with no
     * permission handling of its own.
     *
     * @throws RuntimeException on a chmod failure.
     */
    public static function lockDown(string $path): void
    {
        if (! @chmod($path, 0600)) {
            @unlink($path);

            throw new RuntimeException(
                "Cannot chmod secret material to 0600 (would be left readable): {$path}"
            );
        }
    }
}
