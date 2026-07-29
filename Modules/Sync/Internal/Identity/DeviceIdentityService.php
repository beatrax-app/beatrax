<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\SodiumPrimitives;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Ramsey\Uuid\Uuid;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class DeviceIdentityService
{
    public function __construct(
        private readonly AppLockKeyService $appLockKeyService,
        private readonly FileEncryptor $backupEncryptor,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly DeviceNameDetector $nameDetector,
        private readonly SodiumPrimitives $sodium,
    ) {}

    // Generates the device identity, encrypts the key-file, and persists the
    // device_registry self-row. Returns the in-memory identity DTO (which
    // carries secret-key hex — never persist the DTO).
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws CryptoOperationFailedException on a libsodium failure.
     */
    public function generateAndPersist(int $userId, Session $session): DeviceIdentityDto
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot generate device identity: app-lock not unlocked.');
        }

        try {
            $deviceId = Uuid::uuid4()->toString();
            $createdAt = $this->clock->now()->toIso8601String();

            $signKp = sodium_crypto_sign_keypair();
            // SEPARATE X25519 keypair — do NOT derive from the signing key,
            // since key-reuse across primitives is a crypto anti-pattern.
            $kxKp = sodium_crypto_kx_keypair();

            $ed25519SecretHex = $this->sodium->binToHex(sodium_crypto_sign_secretkey($signKp));
            $ed25519PublicHex = $this->sodium->binToHex(sodium_crypto_sign_publickey($signKp));
            $x25519SecretHex = $this->sodium->binToHex(sodium_crypto_kx_secretkey($kxKp));
            $x25519PublicHex = $this->sodium->binToHex(sodium_crypto_kx_publickey($kxKp));

            // Zero the raw keypair buffers as soon as the hex is extracted —
            // no reason to keep the binary form in memory afterward.
            sodium_memzero($signKp);
            sodium_memzero($kxKp);

            $dto = new DeviceIdentityDto(
                version: 1,
                deviceId: $deviceId,
                userId: $userId,
                ed25519SecretKeyHex: $ed25519SecretHex,
                ed25519PublicKeyHex: $ed25519PublicHex,
                x25519SecretKeyHex: $x25519SecretHex,
                x25519PublicKeyHex: $x25519PublicHex,
                createdAt: $createdAt,
            );

            $payload = json_encode($dto->toArray(), JSON_THROW_ON_ERROR);

            $encPath = UserDataPathService::appPath("sync/identity/{$userId}.enc");
            $identityDir = dirname($encPath);
            @mkdir($identityDir, 0700, true);

            // Stage the plaintext key-file inside the 0700 identity directory
            // created above — NEVER sys_get_temp_dir() — and lock it to 0600
            // immediately, so a crash before the finally-unlink below can
            // never leave the secret keys world-readable.
            $tmpPath = $identityDir.DIRECTORY_SEPARATOR.'beatrax_identity_'.bin2hex(random_bytes(8)).'.tmp';
            SecureTempFile::write($tmpPath, $payload);

            try {
                $this->backupEncryptor->encrypt($tmpPath, $encPath, $kek);
            } finally {
                @unlink($tmpPath);
            }

            $this->db->connection()->table('device_registry')->insert([
                'user_id' => $userId,
                'device_id' => $deviceId,
                'name' => $this->nameDetector->detect(),
                'ed25519_public_key_hex' => $ed25519PublicHex,
                'x25519_public_key_hex' => $x25519PublicHex,
                // The local device verifies its own safety-number against peers
                // at pairing time; the self-row stores an empty placeholder.
                'safety_number_words' => '',
                'is_self' => 1,
                // The local device is implicitly trusted — confirmed and
                // paired the moment its own identity is generated.
                'paired_at' => $createdAt,
                'confirmed_at' => $createdAt,
                'last_seen_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return $dto;
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('device identity generation', $e);
        } finally {
            sodium_memzero($kek);
        }
    }
}
