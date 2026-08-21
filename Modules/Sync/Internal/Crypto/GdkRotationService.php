<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Public\Services\BlindIndexCodec;
use Modules\Sync\Public\Services\DeviceRegistryService;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/device-removal-and-epoch-rotation.md
 */
final class GdkRotationService
{
    // The blind-index key is not an epoch and has no id. Zero is outside
    // GdkEpochId::mint()'s range, so it can never name a real one.
    private const BLIND_INDEX_WRAP_EPOCH_ID = 0;

    public function __construct(
        private readonly GdkKeyringService $keyringService,
        private readonly DeviceRegistryService $deviceRegistry,
        private readonly RelayMailbox $relayMailbox,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SodiumPrimitives $sodium,
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly DeviceKeySigner $signer,
        private readonly BlindIndexCodec $blindIndex,
    ) {}

    // $session is per-method, never constructor-held: this class is a
    // singleton, so a captured Session goes stale across requests.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable (propagated
     *                         from GdkKeyringService — the keyring is never
     *                         touched without the app-lock key).
     * @throws CryptoOperationFailedException on a libsodium failure during rotation.
     */
    public function rotateAndRevoke(int $userId, int $deviceRegistryId, Session $session): void
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toIso8601String();

        // Livewire actions are client-invokable, so a crafted
        // removeDevice(selfRowId) is refused authoritatively here rather than
        // merely hidden in the blade.
        $targetIsSelf = $connection->table('device_registry')
            ->where('id', $deviceRegistryId)
            ->where('user_id', $userId)
            ->value('is_self');
        if ((bool) $targetIsSelf === true) {
            throw new InvalidArgumentException(
                "GdkRotationService::rotateAndRevoke — refusing to revoke the acting device (is_self) for user {$userId}.",
            );
        }

        // Throws on an unavailable KEK BEFORE device_registry is mutated, so a
        // locked-app removal can never commit revoked-but-not-rotated. An empty
        // keyring means encryption was never enabled.
        $keyring = $this->keyringService->loadKeyring($userId, $session);

        $newEpochId = GdkEpochId::mint(array_map(
            static fn (GdkEpoch $epoch): int => $epoch->epochId,
            $keyring->epochs(),
        ));

        // Signs each fan-out wrap. Loaded only after loadKeyring() proved the
        // app-lock KEK is available.
        $identity = $this->requireIdentity($userId, $session);

        $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

        try {
            // Revoke + epoch append + fan-out in ONE SQL transaction, so a
            // later failure can never commit the revoke-only state. Residual:
            // appendEpoch() writes the keyring FILE, outside the transaction.
            $connection->transaction(function () use ($connection, $userId, $deviceRegistryId, $now, $newEpochId, $rawGdkKey, $session, $identity): void {
                // confirmed_at is the exact column DeviceRegistryService's
                // device-key queries filter on, so this one write closes the
                // Ed25519 gate and removes the device from the fan-out below.
                $connection->table('device_registry')
                    ->where('id', $deviceRegistryId)
                    ->where('user_id', $userId)
                    ->update([
                        'confirmed_at' => null,
                        'updated_at' => $now,
                    ]);

                $newEpoch = new GdkEpoch(epochId: $newEpochId, keyHex: $this->sodium->binToHex($rawGdkKey));
                $this->keyringService->appendEpoch($userId, $newEpoch, $session);

                $selfDeviceId = $this->selfDeviceId($userId);

                foreach ($this->deviceRegistry->deviceX25519Keys($userId) as $deviceId => $x25519PublicKeyHex) {
                    if ($selfDeviceId !== null && hash_equals($selfDeviceId, $deviceId)) {
                        continue;
                    }

                    $recipientPub = $this->sodium->hexToBin($x25519PublicKeyHex);
                    $wrap = $this->buildGdkEpochWrap($newEpochId, $rawGdkKey, $recipientPub, $deviceId, $identity->deviceId, $identity->ed25519SecretKeyHex);

                    $this->relayMailbox->deliver(
                        senderDid: $selfDeviceId ?? '',
                        recipientDid: $deviceId,
                        blob: json_encode($wrap, JSON_THROW_ON_ERROR),
                    );
                }
            });
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('GDK rotation', $e);
        } finally {
            sodium_memzero($rawGdkKey);
        }
    }

    // The seal gives confidentiality; the detached Ed25519 signature over the
    // sealed bytes + epoch id + both device ids gives sender authenticity, so a
    // forged wrap is refused rather than the channel being trusted.
    /**
     * @return array{type: string, epoch_id: int, wrapped_key_b64: string, recipient_device_id: string, sender_device_id: string, sig_hex: string, key_role?: string, sender_holds_keyed_rows?: bool}
     *
     * @throws InvalidArgumentException when a required argument is empty.
     * @throws SodiumException on a libsodium failure (translated by callers).
     */
    public function buildGdkEpochWrap(
        int $epochId,
        string $rawGdkKey,
        string $recipientX25519PublicKeyBin,
        string $recipientDeviceId,
        string $senderDeviceId,
        string $senderEd25519SecretKeyHex,
        string $role = GdkEpochWrapSignature::ROLE_EPOCH,
        bool $senderHoldsKeyedRows = false,
    ): array {
        if ($rawGdkKey === '' || $recipientX25519PublicKeyBin === ''
            || $senderDeviceId === '' || $senderEd25519SecretKeyHex === '') {
            throw new InvalidArgumentException(
                'GdkRotationService::buildGdkEpochWrap — rawGdkKey/recipientX25519PublicKeyBin/sender identity must not be empty.',
            );
        }

        $sealed = sodium_crypto_box_seal($rawGdkKey, $recipientX25519PublicKeyBin);

        $senderSecretBin = $this->sodium->hexToBin($senderEd25519SecretKeyHex);

        try {
            $sigHex = $this->signer->sign(
                GdkEpochWrapSignature::signingMessage($epochId, $sealed, $recipientDeviceId, $senderDeviceId, $role, $senderHoldsKeyedRows),
                $senderSecretBin,
            );
        } finally {
            sodium_memzero($senderSecretBin);
        }

        $wrap = [
            'type' => 'GDK_EPOCH_WRAP',
            'epoch_id' => $epochId,
            'wrapped_key_b64' => base64_encode($sealed),
            'recipient_device_id' => $recipientDeviceId,
            'sender_device_id' => $senderDeviceId,
            'sig_hex' => $sigHex,
        ];

        if ($role !== GdkEpochWrapSignature::ROLE_EPOCH) {
            $wrap['key_role'] = $role;
            $wrap['sender_holds_keyed_rows'] = $senderHoldsKeyedRows;
        }

        return $wrap;
    }

    // ADD-device analog of rotateAndRevoke()'s fan-out: wraps EVERY epoch
    // already in $userId's keyring to $newDeviceRegistryId's confirmed
    // X25519 public key. NO rotation, NO revoke, NO appendEpoch() — purely
    // additive delivery of already-existing epochs to a newly-confirmed peer.
    /**
     * @throws \LogicException when the app-lock KEK is unavailable
     *                         (propagated from `GdkKeyringService::loadKeyring()`).
     * @throws CryptoOperationFailedException on a libsodium failure while wrapping.
     */
    public function fanOutAllEpochsToDevice(int $userId, int $newDeviceRegistryId, Session $session): void
    {
        $recipient = $this->resolveFanOutRecipient($userId, $newDeviceRegistryId);
        if ($recipient === null) {
            return;
        }

        // loadKeyring() throws \LogicException when the KEK is unavailable
        // and returns an EMPTY keyring when encryption was never enabled for
        // this user — in that case the foreach below enqueues zero wraps.
        $keyring = $this->keyringService->loadKeyring($userId, $session);
        $selfDeviceId = $this->selfDeviceId($userId);
        $recipientDeviceId = $recipient['deviceId'];

        // The acting device's identity signs each wrap so the new peer can
        // authenticate its provenance (see rotateAndRevoke()).
        $identity = $this->requireIdentity($userId, $session);

        try {
            // Inside the try, like the per-epoch conversion below it. Outside,
            // a libsodium failure here escaped as a raw SodiumException while
            // the identical call a few lines down was translated — one fault
            // reported as two types, depending on which key it was converting.
            $recipientPub = $this->sodium->hexToBin($recipient['pubHex']);

            // The recipient's appendEpoch() advances current_epoch to whatever
            // it applied last, and nothing on the wire says which epoch is
            // current. Delivering the current one last is what makes that
            // arrival order agree with this device's own answer.
            $currentEpochId = $this->currentEpochIdOrNull($userId, $session);

            $this->db->connection()->transaction(function () use ($keyring, $recipientPub, $recipientDeviceId, $selfDeviceId, $identity, $userId, $currentEpochId): void {
                foreach ($keyring->epochs() as $epoch) {
                    if ($epoch->epochId !== $currentEpochId) {
                        $this->deliverWrap($epoch->epochId, $epoch->keyHex, $recipientPub, $recipientDeviceId, $selfDeviceId, $identity);
                    }
                }

                foreach ($keyring->epochs() as $epoch) {
                    if ($epoch->epochId === $currentEpochId) {
                        $this->deliverWrap($epoch->epochId, $epoch->keyHex, $recipientPub, $recipientDeviceId, $selfDeviceId, $identity);
                    }
                }

                // Rides the same sealed, signed, still-confirmed-sender channel
                // as an epoch, tagged so neither side can mistake one for the
                // other. Without it a joining device cannot compute the
                // counterparty key and its imports would fail closed.
                $blindIndexKeyHex = $keyring->blindIndexKeyHex();
                if ($blindIndexKeyHex !== null) {
                    $this->deliverWrap(
                        self::BLIND_INDEX_WRAP_EPOCH_ID,
                        $blindIndexKeyHex,
                        $recipientPub,
                        $recipientDeviceId,
                        $selfDeviceId,
                        $identity,
                        GdkEpochWrapSignature::ROLE_BLIND_INDEX,
                        $this->blindIndex->holdsDerivedRows($userId),
                    );
                }
            });
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('GDK epoch fan-out to a device', $e);
        }
    }

    /**
     * @throws SodiumException on a libsodium failure (translated by the caller).
     */
    private function deliverWrap(
        int $epochId,
        string $keyHex,
        string $recipientPub,
        string $recipientDeviceId,
        ?string $selfDeviceId,
        DeviceIdentityDto $identity,
        string $role = GdkEpochWrapSignature::ROLE_EPOCH,
        bool $senderHoldsKeyedRows = false,
    ): void {
        $rawKey = $this->sodium->hexToBin($keyHex);

        try {
            $wrap = $this->buildGdkEpochWrap($epochId, $rawKey, $recipientPub, $recipientDeviceId, $identity->deviceId, $identity->ed25519SecretKeyHex, $role, $senderHoldsKeyedRows);

            $this->relayMailbox->deliver(
                senderDid: $selfDeviceId ?? '',
                recipientDid: $recipientDeviceId,
                blob: json_encode($wrap, JSON_THROW_ON_ERROR),
            );
        } finally {
            sodium_memzero($rawKey);
        }
    }

    // Null when encryption was never enabled, or when the recorded epoch has
    // no key in the keyring — a stranded state EncryptionMigrationService
    // reports separately, and not one this fan-out should decide anything on.
    private function currentEpochIdOrNull(int $userId, Session $session): ?int
    {
        try {
            return $this->keyringService->currentEpoch($userId, $session)->epochId;
        } catch (\LogicException|\RuntimeException) {
            return null;
        }
    }

    // Only a confirmed, non-self device carrying both a device_id and an
    // X25519 public key is an eligible fan-out target. Never wrapping to an
    // unconfirmed device is threat-model defense-in-depth; the self-exclusion
    // mirrors rotateAndRevoke() — the acting device already holds every epoch.
    /**
     * @return array{deviceId: string, pubHex: string}|null
     */
    private function resolveFanOutRecipient(int $userId, int $newDeviceRegistryId): ?array
    {
        $recipient = $this->db->connection()->table('device_registry')
            ->where('id', $newDeviceRegistryId)
            ->where('user_id', $userId)
            ->first(['device_id', 'x25519_public_key_hex', 'is_self', 'confirmed_at']);

        if ($recipient === null) {
            return null;
        }

        $deviceId = is_string($recipient->device_id) ? $recipient->device_id : null;
        $pubHex = is_string($recipient->x25519_public_key_hex) ? $recipient->x25519_public_key_hex : null;

        if ($recipient->confirmed_at === null
            || (bool) $recipient->is_self === true
            || $deviceId === null
            || $pubHex === null
        ) {
            return null;
        }

        return ['deviceId' => $deviceId, 'pubHex' => $pubHex];
    }

    private function selfDeviceId(int $userId): ?string
    {
        $value = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        return is_string($value) ? $value : null;
    }

    // The acting device's own identity, needed to SIGN each epoch wrap. Loaded
    // only after loadKeyring() proved the KEK is available, so a null here is an
    // unexpected state (KEK present, identity absent), not the ordinary locked
    // case — fail closed rather than emit an unsigned, un-adoptable wrap.
    private function requireIdentity(int $userId, Session $session): DeviceIdentityDto
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            throw new \LogicException(
                "GdkRotationService — no local device identity for user {$userId}; cannot sign epoch wraps.",
            );
        }

        return $identity;
    }
}
