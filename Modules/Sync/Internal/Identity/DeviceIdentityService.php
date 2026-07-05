<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\BackupEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use SodiumException;

/**
 * Generate + persist the per-(user, install) device identity (PAIR-01, D-01/
 * D-02/D-03/D-12).
 *
 * On enable-sync this generates a long-term Ed25519 signing keypair and a
 * SEPARATE X25519 key-agreement keypair, writes them to a key-file encrypted
 * with the v1.2 BackupEncryptor (Argon2id + XChaCha20-Poly1305) under the
 * app-lock KEK released by AppLockKeyService::release(), and inserts the
 * self-row into device_registry (public keys only — no secret key ever reaches
 * the DB).
 *
 * D-02 weak-key-window guard (T-12-06): generation HARD-throws when the KEK is
 * null. The key-file is never written under anything weaker than the LOCK-04
 * key. This service-level guard is defense-in-depth alongside the Plan 04 UI
 * gate — a second entry point (console command, future API) cannot bypass it.
 */
final class DeviceIdentityService
{
    public function __construct(
        private readonly AppLockKeyService $appLockKeyService,
        private readonly BackupEncryptor $backupEncryptor,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly DeviceNameDetector $nameDetector,
    ) {}

    /**
     * Generate the device identity, encrypt the key-file, and persist the
     * device_registry self-row. Returns the in-memory identity DTO (which
     * carries secret-key hex — never persist the DTO).
     *
     * @throws \LogicException when the app-lock KEK is unavailable (D-02 gate).
     * @throws RuntimeException on a crypto / I-O failure.
     */
    public function generateAndPersist(int $userId, Session $session): DeviceIdentityDto
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            // T-12-06 / Pitfall 1: never write the key-file without the LOCK-04 KEK.
            throw new \LogicException('Cannot generate device identity: app-lock not unlocked.');
        }

        try {
            $deviceId = Uuid::uuid4()->toString();
            $createdAt = $this->clock->now()->toIso8601String();

            $signKp = sodium_crypto_sign_keypair();
            // SEPARATE X25519 keypair — do NOT derive from the signing key
            // (key-reuse across primitives is a crypto anti-pattern, RESEARCH §Pattern 1).
            $kxKp = sodium_crypto_kx_keypair();

            $ed25519SecretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($signKp));
            $ed25519PublicHex = sodium_bin2hex(sodium_crypto_sign_publickey($signKp));
            $x25519SecretHex = sodium_bin2hex(sodium_crypto_kx_secretkey($kxKp));
            $x25519PublicHex = sodium_bin2hex(sodium_crypto_kx_publickey($kxKp));

            // Zero the raw keypair buffers as soon as the hex is extracted.
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
            // created above — NEVER sys_get_temp_dir(), which is world-
            // traversable (e.g. /tmp at mode 1777) — and lock it to 0600
            // immediately (SecureTempFile::write throws if the chmod fails),
            // so a crash between staging and the finally-unlink below can
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
                // The local device is implicitly trusted: confirmed + paired now.
                'paired_at' => $createdAt,
                'confirmed_at' => $createdAt,
                'last_seen_at' => null,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            return $dto;
        } catch (SodiumException $e) {
            throw new RuntimeException('Failed to generate device identity: sodium error.', 0, $e);
        } finally {
            sodium_memzero($kek);
        }
    }
}
