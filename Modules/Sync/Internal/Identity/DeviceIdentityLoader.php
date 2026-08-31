<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Illuminate\Contracts\Session\Session;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Exceptions\BackupDecryptionException;
use Modules\Core\Public\Exceptions\BackupFormatException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\SecretFileException;

/**
 * @link ../../../../.docs/features/sync/device-identity-key-files.md
 */
final readonly class DeviceIdentityLoader
{
    private const string STAGING_PREFIX = 'beatrax_identity_read_';

    private SealedJsonFile $sealedFile;

    public function __construct(
        private AppLockKeyService $appLockKeyService,
        FileEncryptor $backupEncryptor,
    ) {
        $this->sealedFile = new SealedJsonFile($backupEncryptor);
    }

    // Whether a key-file exists at all, WITHOUT needing the KEK. load()
    // folds "never enabled" and "locked" into the same null, and a caller
    // that mints on null would overwrite a locked device's existing identity
    // — separating the two is what makes minting safe.
    public function exists(int $userId): bool
    {
        return file_exists(UserDataPathService::appPath("sync/identity/{$userId}.enc"));
    }

    // Null for every state but Usable: sync was never enabled, the app-lock is
    // locked, or the key-file will not open under the key this device holds.
    // A caller that must tell those apart — because it would mint, or because
    // it tells the user which one it is — asks state() instead.
    /**
     * @throws SecretFileException on an I/O failure reading an EXISTING key-file.
     */
    public function load(int $userId, Session $session): ?DeviceIdentityDto
    {
        [, $identity] = $this->read($userId, $session);

        return $identity;
    }

    // Both answers from one decrypt, for a caller that uses the identity when
    // there is one and names the state to a reader when there is not. Asking
    // load() then state() reads and unseals the same file twice.
    /**
     * @return array{DeviceIdentityState, ?DeviceIdentityDto}
     *
     * @throws SecretFileException on an I/O failure reading an EXISTING key-file.
     */
    public function loadWithState(int $userId, Session $session): array
    {
        return $this->read($userId, $session);
    }

    /**
     * @throws SecretFileException on an I/O failure reading an EXISTING key-file.
     */
    public function state(int $userId, Session $session): DeviceIdentityState
    {
        [$state] = $this->read($userId, $session);

        return $state;
    }

    /**
     * @return array{DeviceIdentityState, ?DeviceIdentityDto}
     *
     * @throws SecretFileException on an I/O failure reading an EXISTING key-file.
     */
    private function read(int $userId, Session $session): array
    {
        // Sync never enabled for this user, or the app is locked (no KEK to
        // decrypt with) — either way, no usable identity right now.
        $encPath = UserDataPathService::appPath("sync/identity/{$userId}.enc");
        if (! file_exists($encPath)) {
            return [DeviceIdentityState::Absent, null];
        }

        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            return [DeviceIdentityState::Locked, null];
        }

        return $this->open($encPath, $kek);
    }

    /**
     * @return array{DeviceIdentityState, ?DeviceIdentityDto}
     *
     * @throws SecretFileException on an I/O failure reading an EXISTING key-file.
     */
    private function open(string $encPath, string $kek): array
    {
        try {
            $json = $this->sealedFile->readPlaintext($encPath, $kek, self::STAGING_PREFIX);
        } catch (BackupDecryptionException|BackupFormatException) {
            // The key-file outlives the database that wraps the KEK, so a
            // restored or replaced database leaves one that opens for nobody.
            // That is a state of this device, not a fault — every caller of
            // load() reads a settings screen or a poll handler.
            return [DeviceIdentityState::Unreadable, null];
        } finally {
            sodium_memzero($kek);
        }

        /** @var array<string, mixed> $data */
        $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return [DeviceIdentityState::Usable, DeviceIdentityDto::fromArray($data)];
    }
}
