<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use RuntimeException;

/**
 * Decrypt + load the device identity key-file (PAIR-01, D-01).
 *
 * Returns null when sync was never enabled (no key-file) or the app-lock is
 * locked (no KEK) — callers treat null as "no usable identity right now" and
 * either surface the unlock screen or run keyless (the DeviceKeySigner stays a
 * null-object). The decrypted plaintext is written to a temp file, read, then
 * unlinked; the KEK is zeroed in a finally block.
 */
final class DeviceIdentityLoader
{
    public function __construct(
        private readonly AppLockKeyService $appLockKeyService,
        private readonly BackupEncryptor $backupEncryptor,
    ) {}

    /**
     * Load + decrypt the identity for $userId, or null when unavailable.
     *
     * @throws RuntimeException on a decrypt / I-O failure of an EXISTING key-file.
     */
    public function load(int $userId, Session $session): ?DeviceIdentityDto
    {
        $encPath = UserDataPathService::appPath("sync/identity/{$userId}.enc");
        if (! file_exists($encPath)) {
            return null; // Sync never enabled for this user.
        }

        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            return null; // App locked — no KEK to decrypt with.
        }

        $tmpPath = sys_get_temp_dir().'/beatrax_identity_read_'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $this->backupEncryptor->decrypt($encPath, $tmpPath, $kek);
            $json = file_get_contents($tmpPath);
            if ($json === false) {
                throw new RuntimeException('Failed to read the decrypted device identity key-file.');
            }
        } finally {
            @unlink($tmpPath);
            sodium_memzero($kek);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return DeviceIdentityDto::fromArray($data);
    }
}
