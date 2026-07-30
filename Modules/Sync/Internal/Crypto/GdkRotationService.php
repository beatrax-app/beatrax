<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Exceptions\CryptoOperationFailedException;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Public\Services\DeviceRegistryService;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class GdkRotationService
{
    // Device removal = trust revocation + forward-only GDK epoch rotation, in
    // ONE operation. A rotate-only or revoke-only implementation is a
    // HIGH-severity access-control gap. Order: revoke device_registry trust
    // first, THEN rotate the epoch, THEN fan out the new epoch (see @link).
    public function __construct(
        private readonly GdkKeyringService $keyringService,
        private readonly DeviceRegistryService $deviceRegistry,
        private readonly RelayMailbox $relayMailbox,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly SodiumPrimitives $sodium,
    ) {}

    // $session is a PER-METHOD parameter, not a constructor-captured field —
    // this class is bound as a singleton, so a constructor-held Session would
    // be captured once at first resolve and go stale across requests/the
    // device-removal daemon path.
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

        // Never allow the acting device (is_self = 1) to be revoked. Livewire
        // actions are client-invokable, so a crafted removeDevice(selfRowId)
        // must be rejected AUTHORITATIVELY here, not merely hidden in the
        // blade — self-revocation would drop the user out of their own trusted-device set.
        $targetIsSelf = $connection->table('device_registry')
            ->where('id', $deviceRegistryId)
            ->where('user_id', $userId)
            ->value('is_self');
        if ((bool) $targetIsSelf === true) {
            throw new InvalidArgumentException(
                "GdkRotationService::rotateAndRevoke — refusing to revoke the acting device (is_self) for user {$userId}.",
            );
        }

        // Fail fast if the app-lock KEK is unavailable BEFORE mutating
        // device_registry, so a locked-app removal can never leave the device
        // revoked-but-not-rotated. Empty keyring means encryption is not yet
        // enabled (group-of-one bootstrap -> epoch 1).
        $keyring = $this->keyringService->loadKeyring($userId, $session);
        $newEpochId = 1;
        foreach ($keyring->epochs() as $epoch) {
            $newEpochId = max($newEpochId, $epoch->epochId + 1);
        }

        $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

        try {
            // Revoke + epoch append (current_epoch advance) + fan-out run in
            // ONE SQL transaction, so a failure anywhere after the revoke can
            // no longer COMMIT the revoke-only state. Residual: appendEpoch()
            // writes the keyring FILE, which cannot join the SQL transaction.
            $connection->transaction(function () use ($connection, $userId, $deviceRegistryId, $now, $newEpochId, $rawGdkKey, $session): void {
                // Step 1: revoke trust. Clearing confirmed_at is the exact
                // column DeviceRegistryService's device-key queries already
                // filter on, so this single write closes the Ed25519 gate.
                $connection->table('device_registry')
                    ->where('id', $deviceRegistryId)
                    ->where('user_id', $userId)
                    ->update([
                        'confirmed_at' => null,
                        'updated_at' => $now,
                    ]);

                $newEpoch = new GdkEpoch(epochId: $newEpochId, keyHex: $this->sodium->binToHex($rawGdkKey));
                $this->keyringService->appendEpoch($userId, $newEpoch, $session);

                // Step 3: wrap-per-remaining-device fan-out over the ZK-pure
                // relay mailbox. Excludes self and the just-revoked device
                // (already absent from deviceX25519Keys() after Step 1).
                $selfDeviceId = $this->selfDeviceId($userId);

                foreach ($this->deviceRegistry->deviceX25519Keys($userId) as $deviceId => $x25519PublicKeyHex) {
                    if ($selfDeviceId !== null && hash_equals($selfDeviceId, $deviceId)) {
                        continue;
                    }

                    $recipientPub = $this->sodium->hexToBin($x25519PublicKeyHex);
                    $wrap = $this->buildGdkEpochWrap($newEpochId, $rawGdkKey, $recipientPub, $deviceId);

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

    // Builds the opaque GDK_EPOCH_WRAP blob for one recipient device.
    // SECURITY PRECONDITION: sodium_crypto_box_seal provides confidentiality
    // but NO sender authentication, so this wrap is safe to trust on receipt
    // ONLY over a channel that has independently authenticated the sender as a CONFIRMED peer.
    /**
     * @return array{type: string, epoch_id: int, wrapped_key_b64: string, recipient_device_id: string}
     *
     * @throws InvalidArgumentException when $rawGdkKey or
     *                                  $recipientX25519PublicKeyBin is empty.
     */
    public function buildGdkEpochWrap(
        int $epochId,
        string $rawGdkKey,
        string $recipientX25519PublicKeyBin,
        string $recipientDeviceId,
    ): array {
        if ($rawGdkKey === '' || $recipientX25519PublicKeyBin === '') {
            throw new InvalidArgumentException(
                'GdkRotationService::buildGdkEpochWrap — rawGdkKey/recipientX25519PublicKeyBin must not be empty.',
            );
        }

        $sealed = sodium_crypto_box_seal($rawGdkKey, $recipientX25519PublicKeyBin);

        return [
            'type' => 'GDK_EPOCH_WRAP',
            'epoch_id' => $epochId,
            'wrapped_key_b64' => base64_encode($sealed),
            'recipient_device_id' => $recipientDeviceId,
        ];
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
        $connection = $this->db->connection();

        $recipient = $connection->table('device_registry')
            ->where('id', $newDeviceRegistryId)
            ->where('user_id', $userId)
            ->first(['device_id', 'x25519_public_key_hex', 'is_self', 'confirmed_at']);

        if ($recipient === null) {
            return;
        }

        // Defense-in-depth (threat-model item 1): never wrap to an
        // unconfirmed device, even if this method is ever mis-called.
        if ($recipient->confirmed_at === null) {
            return;
        }

        // No wrap-to-self, mirroring rotateAndRevoke()'s own self-exclusion —
        // the acting device already has every epoch in its own keyring.
        if ((bool) $recipient->is_self === true) {
            return;
        }

        $recipientDeviceId = is_string($recipient->device_id) ? $recipient->device_id : null;
        $recipientPubHex = is_string($recipient->x25519_public_key_hex) ? $recipient->x25519_public_key_hex : null;

        if ($recipientDeviceId === null || $recipientPubHex === null) {
            return;
        }

        // loadKeyring() throws \LogicException when the KEK is unavailable
        // and returns an EMPTY keyring when encryption was never enabled for
        // this user — in that case the foreach below enqueues zero wraps.
        $keyring = $this->keyringService->loadKeyring($userId, $session);
        $selfDeviceId = $this->selfDeviceId($userId);

        try {
            // Inside the try, like the per-epoch conversion below it. Outside,
            // a libsodium failure here escaped as a raw SodiumException while
            // the identical call a few lines down was translated — one fault
            // reported as two types, depending on which key it was converting.
            $recipientPub = $this->sodium->hexToBin($recipientPubHex);

            $connection->transaction(function () use ($keyring, $recipientPub, $recipientDeviceId, $selfDeviceId): void {
                foreach ($keyring->epochs() as $epoch) {
                    $rawKey = $this->sodium->hexToBin($epoch->keyHex);

                    try {
                        $wrap = $this->buildGdkEpochWrap($epoch->epochId, $rawKey, $recipientPub, $recipientDeviceId);

                        $this->relayMailbox->deliver(
                            senderDid: $selfDeviceId ?? '',
                            recipientDid: $recipientDeviceId,
                            blob: json_encode($wrap, JSON_THROW_ON_ERROR),
                        );
                    } finally {
                        sodium_memzero($rawKey);
                    }
                }
            });
        } catch (SodiumException $e) {
            throw CryptoOperationFailedException::during('GDK epoch fan-out to a device', $e);
        }
    }

    private function selfDeviceId(int $userId): ?string
    {
        $value = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        return is_string($value) ? $value : null;
    }
}
