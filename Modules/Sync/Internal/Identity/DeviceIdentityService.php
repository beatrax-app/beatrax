<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Identity;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\Instant;
use Modules\Sync\Internal\Crypto\SodiumPrimitives;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Exceptions\DeviceIdentityUnreadableException;
use Modules\Sync\Public\Events\DeviceSyncEnabled;
use Ramsey\Uuid\Uuid;
use SodiumException;

final readonly class DeviceIdentityService
{
    private const string STAGING_PREFIX = 'beatrax_identity_';

    private SealedJsonFile $sealedFile;

    public function __construct(
        private AppLockKeyService $appLockKeyService,
        FileEncryptor $backupEncryptor,
        private DatabaseManager $db,
        private Clock $clock,
        private DeviceNameDetector $nameDetector,
        private DeviceIdentityLoader $loader,
        private SodiumPrimitives $sodium,
        private Dispatcher $events,
    ) {
        $this->sealedFile = new SealedJsonFile($backupEncryptor);
    }

    // Puts back the self-row for an identity whose key-file is still on disk.
    // Keyed on device_id so a surviving row is refreshed, not duplicated.
    // confirmed_at is set because deviceKeys() hands peers only confirmed
    // devices, and an unconfirmed self-row hides this device's own entries.
    private function restoreSelfRow(int $userId, DeviceIdentityDto $identity): void
    {
        $now = Instant::zulu($this->clock->now());

        // A key-file minted before the column was Zulu holds a local offset,
        // and this is the one path that copies it back onto a migrated row.
        $mintedAt = Instant::zulu(CarbonImmutable::parse($identity->createdAt));

        $row = [
            'name' => $this->nameDetector->detect(),
            'ed25519_public_key_hex' => $identity->ed25519PublicKeyHex,
            'x25519_public_key_hex' => $identity->x25519PublicKeyHex,
            'safety_number_words' => '',
            'is_self' => 1,
            'paired_at' => $mintedAt,
            'confirmed_at' => $mintedAt,
            'updated_at' => $now,
        ];

        $existing = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('device_id', $identity->deviceId);

        if ((clone $existing)->exists()) {
            $existing->update($row);

            return;
        }

        $this->db->connection()->table('device_registry')->insert([
            ...$row,
            'user_id' => $userId,
            'device_id' => $identity->deviceId,
            'last_seen_at' => null,
            'created_at' => $mintedAt,
        ]);
    }

    // The one consented way past generateAndPersist()'s refusal below, for a
    // key-file that opens for nobody on this device. It is moved ASIDE, never
    // deleted: the database wrapping the KEK that opens it may still be
    // restored, and it holds the only copy of keys the op-log is signed with.
    /**
     * @return bool whether a key-file was actually retired
     *
     * @throws DeviceIdentityUnreadableException when the unreadable key-file cannot be moved aside.
     */
    public function retireUnreadableIdentity(int $userId, Session $session): bool
    {
        if ($this->loader->state($userId, $session) !== DeviceIdentityState::Unreadable) {
            return false;
        }

        $encPath = UserDataPathService::appPath("sync/identity/{$userId}.enc");

        // Timestamped AND random: a second retirement inside the same second
        // must not land on the first, and every reader looks for the exact
        // "{userId}.enc" name, so a sibling of it is invisible to all of them.
        $retiredPath = $encPath.'.unreadable-'
            .$this->clock->now()->format('Ymd-His').'-'
            .bin2hex(random_bytes(4));

        if (! @rename($encPath, $retiredPath)) {
            throw DeviceIdentityUnreadableException::couldNotRetire($encPath);
        }

        return true;
    }

    // Generates the device identity, encrypts the key-file, and persists the
    // device_registry self-row. Returns the in-memory identity DTO (which
    // carries secret-key hex — never persist the DTO).
    /**
     * @throws \LogicException when the app-lock KEK is unavailable.
     * @throws DeviceIdentityUnreadableException when a key-file exists that the held KEK cannot open.
     * @throws CryptoOperationFailedException on a libsodium failure.
     */
    public function generateAndPersist(int $userId, Session $session): DeviceIdentityDto
    {
        $kek = $this->appLockKeyService->release($session);
        if ($kek === null) {
            throw new \LogicException('Cannot generate device identity: app-lock not unlocked.');
        }

        // Switching sync off and on again MUST NOT mint a second identity:
        // every op is signed by the identity that wrote it, so replacing the
        // key-file orphans the whole history and the device quietly loses the
        // ability to hand its data to anyone. Restore, do not replace.
        $existing = $this->loader->load($userId, $session);
        if ($existing !== null) {
            $this->restoreSelfRow($userId, $existing);
            $this->events->dispatch(new DeviceSyncEnabled($userId));
            sodium_memzero($kek);

            return $existing;
        }

        // A null from load() with a KEK in hand is either no key-file or one
        // that will not open under it, and the second is not a fresh device:
        // minting here would write over secret keys a restored database could
        // still use. retireUnreadableIdentity() is the consented way past this.
        if ($this->loader->state($userId, $session) === DeviceIdentityState::Unreadable) {
            sodium_memzero($kek);

            throw DeviceIdentityUnreadableException::willNotOverwrite($userId);
        }

        try {
            $deviceId = Uuid::uuid4()->toString();
            $createdAt = Instant::zulu($this->clock->now());

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

            $this->sealedFile->writeSealed(
                UserDataPathService::appPath("sync/identity/{$userId}.enc"),
                $payload,
                $kek,
                self::STAGING_PREFIX,
            );

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

            // This row IS the "sync is on" signal the desktop boot hook reads,
            // so announce it: a device that enables sync mid-session must
            // become reachable without waiting for a restart.
            $this->events->dispatch(new DeviceSyncEnabled($userId));

            return $dto;
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('device identity generation', $e);
        } finally {
            sodium_memzero($kek);
        }
    }
}
