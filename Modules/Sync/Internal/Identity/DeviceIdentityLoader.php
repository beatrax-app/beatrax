<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use RuntimeException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class DeviceIdentityLoader
{
    public function __construct(
        private readonly AppLockKeyService $appLockKeyService,
        private readonly BackupEncryptor $backupEncryptor,
    ) {}

    // Returns null when sync was never enabled (no key-file) or the app-lock
    // is locked (no KEK) — callers treat null as "no usable identity right
    // now" and either surface the unlock screen or run keyless.
    /**
     * @throws RuntimeException on a decrypt / I-O failure of an EXISTING key-file.
     */
    public function load(int $userId, Session $session): ?DeviceIdentityDto
    {
        // Sync never enabled for this user, or the app is locked (no KEK to
        // decrypt with) — either way, no usable identity right now.
        $encPath = UserDataPathService::appPath("sync/identity/{$userId}.enc");
        if (! file_exists($encPath)) {
            return null;
        }

        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            return null;
        }

        // Stage the decrypted plaintext inside the identity directory itself
        // — NEVER sys_get_temp_dir(), which is world-traversable (e.g. /tmp
        // at mode 1777).
        $identityDir = dirname($encPath);
        $tmpPath = $identityDir.DIRECTORY_SEPARATOR.'beatrax_identity_read_'.bin2hex(random_bytes(8)).'.tmp';
        try {
            $this->backupEncryptor->decrypt($encPath, $tmpPath, $kek);
            // BackupEncryptor renames its own internal staging file onto
            // $tmpPath, which lands at the process umask default — lock it
            // to 0600 before ever reading the plaintext back out.
            SecureTempFile::lockDown($tmpPath);
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
