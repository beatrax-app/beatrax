<?php

declare(strict_types=1);

namespace Modules\Sync\Public\Services;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Throwable;

// The Noise static keypair `sync:serve` answers handshakes with, handed to
// the daemon as environment at spawn. The identity is sealed with the
// app-lock KEK, which exists only inside an unlocked session, so a headless
// daemon can never open it for itself.

// SCOPE: the X25519 TRANSPORT keypair only. The Ed25519 signing key stays
// sealed, so a reader of this environment can impersonate the device on the
// LAN but cannot forge an op-log entry. Environment rather than a file, so
// it never rests on disk in the clear.
final readonly class SyncDaemonIdentity
{
    public const string ENV_USER = 'BEATRAX_SYNC_USER_ID';

    public const string ENV_DEVICE = 'BEATRAX_SYNC_DEVICE_ID';

    public const string ENV_SECRET = 'BEATRAX_SYNC_X25519_SECRET_HEX';

    public const string ENV_PUBLIC = 'BEATRAX_SYNC_X25519_PUBLIC_HEX';

    public function __construct(
        private DeviceIdentityLoader $identityLoader,
    ) {}

    // Null when the identity cannot be opened — locked, or sync never
    // enabled. Callers then leave the daemon as it is rather than restarting
    // it into a state that rejects every peer.
    /**
     * @return array<string, string>|null
     */
    public function env(int $userId, Session $session): ?array
    {
        try {
            $identity = $this->identityLoader->load($userId, $session);
        } catch (Throwable) {
            return null;
        }

        if ($identity === null) {
            return null;
        }

        return [
            self::ENV_USER => (string) $identity->userId,
            self::ENV_DEVICE => $identity->deviceId,
            self::ENV_SECRET => $identity->x25519SecretKeyHex,
            self::ENV_PUBLIC => $identity->x25519PublicKeyHex,
        ];
    }

    // Read back inside the daemon process. Null whenever any part is absent,
    // so a partially-configured spawn rejects peers instead of half-working.
    /**
     * @return array{userId: int, deviceId: string, secret: string, public: string}|null
     */
    public static function fromEnvironment(): ?array
    {
        $userId = getenv(self::ENV_USER);
        $deviceId = getenv(self::ENV_DEVICE);
        $secret = getenv(self::ENV_SECRET);
        $public = getenv(self::ENV_PUBLIC);

        if (! is_string($userId) || ! is_string($deviceId) || ! is_string($secret) || ! is_string($public)) {
            return null;
        }

        if ($deviceId === '' || $secret === '' || $public === '' || ! is_numeric($userId)) {
            return null;
        }

        return [
            'userId' => (int) $userId,
            'deviceId' => $deviceId,
            'secret' => $secret,
            'public' => $public,
        ];
    }
}
