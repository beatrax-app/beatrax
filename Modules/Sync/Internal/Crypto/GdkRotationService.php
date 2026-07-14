<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Public\Services\DeviceRegistryService;
use RuntimeException;
use SodiumException;

/**
 * CRYPT-02 core: device removal = trust revocation + forward-only GDK epoch
 * rotation, in ONE operation (T-14-04). A rotate-only or revoke-only
 * implementation is a HIGH-severity access-control gap — RESEARCH's Security
 * Domain mandates BOTH.
 *
 * ## Order of operations (deliberate)
 *
 * 1. Revoke `device_registry` trust for the removed device FIRST (clear
 *    `confirmed_at`, mirroring how `DeviceRegistryService::deviceKeys()` /
 *    `deviceX25519Keys()` / `confirmedDevices()` already filter on
 *    `confirmed_at IS NOT NULL`) — this closes the Ed25519 gate before any
 *    new epoch key is even generated, so there is no window where the
 *    removed device is both still-trusted AND aware of the new epoch.
 * 2. Generate a fresh GDK epoch key (D-04 forward-only: `appendEpoch()`
 *    never discards a prior epoch — Pitfall 4) and advance
 *    `sync_encryption_state.current_epoch`.
 * 3. Wrap the new epoch to every REMAINING confirmed device's X25519
 *    public key via `sodium_crypto_box_seal` (D-05) and enqueue each
 *    opaque blob on the ZK-pure `RelayMailbox` for offline pickup —
 *    `RelayMailbox` never inspects the blob (T-13-06 invariant preserved).
 *
 * `rotateAndRevoke()` tolerates encryption not yet being enabled for the
 * user (no `sync_encryption_state` row / empty keyring) by treating that as
 * a group-of-one bootstrap — the new epoch becomes epoch 1 — rather than
 * requiring `GdkKeyringService::currentEpoch()`'s hard "no current epoch
 * recorded" failure.
 *
 * Removing the last remaining peer still rotates locally and enqueues zero
 * wraps (nothing to fan out to).
 */
final class GdkRotationService
{
    public function __construct(
        private readonly GdkKeyringService $keyringService,
        private readonly DeviceRegistryService $deviceRegistry,
        private readonly RelayMailbox $relayMailbox,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
    ) {}

    /**
     * Revoke $deviceRegistryId's trust and rotate the GDK epoch for $userId,
     * fanning the new epoch out (sealed-box wrapped) to every remaining
     * confirmed device.
     *
     * $session is a PER-METHOD parameter (D-11), not a constructor-captured
     * field — this class is bound as a singleton (`SyncServiceProvider`), so
     * a constructor-held `Session` would be captured once at first resolve
     * and go stale across requests/the device-removal daemon path. Mirrors
     * `GdkKeyringService`/`SensitiveColumnCodec`'s existing per-method-Session
     * convention.
     *
     * @throws \LogicException when the app-lock KEK is unavailable (propagated
     *                         from GdkKeyringService — the keyring is never
     *                         touched without the LOCK-04 key).
     * @throws RuntimeException on a crypto / I-O failure during rotation.
     */
    public function rotateAndRevoke(int $userId, int $deviceRegistryId, Session $session): void
    {
        $connection = $this->db->connection();
        $now = $this->clock->now()->toIso8601String();

        // WR-06: never allow the acting device (`is_self = 1`) to be revoked.
        // Livewire actions are client-invokable, so a crafted
        // removeDevice(selfRowId) must be rejected AUTHORITATIVELY here — not
        // merely hidden in the blade — before any write. Self-revocation would
        // clear the user's own `confirmed_at` and drop them out of their own
        // trusted-device set.
        $targetIsSelf = $connection->table('device_registry')
            ->where('id', $deviceRegistryId)
            ->where('user_id', $userId)
            ->value('is_self');
        if ((bool) $targetIsSelf === true) {
            throw new InvalidArgumentException(
                "GdkRotationService::rotateAndRevoke — refusing to revoke the acting device (is_self) for user {$userId}.",
            );
        }

        // CR-02: fail fast if the app-lock KEK is unavailable BEFORE mutating
        // device_registry. loadKeyring() is read-only and throws LogicException
        // when locked; doing it here (rather than after the revoke write)
        // guarantees a locked-app removal can never leave the device
        // revoked-but-not-rotated — the exact revoke-only state this class
        // declares a HIGH-severity access-control gap. The epoch key itself is
        // still generated AFTER the revoke inside the transaction below, so the
        // documented "close the Ed25519 gate before the new epoch exists"
        // ordering is preserved. loadKeyring() returns an empty keyring when
        // encryption is not yet enabled (group-of-one bootstrap → epoch 1).
        $keyring = $this->keyringService->loadKeyring($userId, $session);
        $newEpochId = 1;
        foreach ($keyring->epochs() as $epoch) {
            $newEpochId = max($newEpochId, $epoch->epochId + 1);
        }

        $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

        try {
            // CR-02: revoke + epoch append (current_epoch advance) + fan-out run
            // in ONE SQL transaction, so a failure anywhere after the revoke can
            // no longer COMMIT the revoke-only state — every DB write rolls back
            // together and the removal can be retried cleanly.
            //
            // Residual (documented; CR-02 marked requires-human-verification):
            // appendEpoch() writes the keyring FILE, which cannot join the SQL
            // transaction. On a post-append rollback the file keeps the appended
            // (unused) epoch key — forward-only append makes that benign, but a
            // retry mints a fresh epoch id rather than resuming. A fully
            // idempotent staged-finalize rotation is deferred.
            $connection->transaction(function () use ($connection, $userId, $deviceRegistryId, $now, $newEpochId, $rawGdkKey, $session): void {
                // Step 1 (T-14-04): revoke trust. Clearing confirmed_at is the
                // exact column DeviceRegistryService::deviceKeys()/
                // deviceX25519Keys()/confirmedDevices() already filter on
                // (whereNotNull('confirmed_at')), so this single write closes
                // the Ed25519 gate — the removed device drops out of every
                // confirmed-device query from this point on.
                $connection->table('device_registry')
                    ->where('id', $deviceRegistryId)
                    ->where('user_id', $userId)
                    ->update([
                        'confirmed_at' => null,
                        'updated_at' => $now,
                    ]);

                // Step 2 (D-04): forward-only epoch rotation.
                $newEpoch = new GdkEpoch(epochId: $newEpochId, keyHex: sodium_bin2hex($rawGdkKey));
                $this->keyringService->appendEpoch($userId, $newEpoch, $session);

                // Step 3 (D-05): wrap-per-remaining-device fan-out over the
                // ZK-pure relay mailbox. Excludes self (no wrap-to-self — the
                // acting device already appended the epoch to its own keyring)
                // and the just-revoked device (already absent from
                // deviceX25519Keys() after Step 1).
                $selfDeviceId = $this->selfDeviceId($userId);

                foreach ($this->deviceRegistry->deviceX25519Keys($userId) as $deviceId => $x25519PublicKeyHex) {
                    if ($selfDeviceId !== null && hash_equals($selfDeviceId, $deviceId)) {
                        continue;
                    }

                    $recipientPub = sodium_hex2bin($x25519PublicKeyHex);
                    $wrap = $this->buildGdkEpochWrap($newEpochId, $rawGdkKey, $recipientPub, $deviceId);

                    $this->relayMailbox->deliver(
                        senderDid: $selfDeviceId ?? '',
                        recipientDid: $deviceId,
                        blob: json_encode($wrap, JSON_THROW_ON_ERROR),
                    );
                }
            });
        } catch (SodiumException $e) {
            throw new RuntimeException('GdkRotationService::rotateAndRevoke — sodium error during rotation.', 0, $e);
        } finally {
            sodium_memzero($rawGdkKey);
        }
    }

    /**
     * Build the opaque `GDK_EPOCH_WRAP` control-message blob for one
     * recipient device — $rawGdkKey sealed-box-encrypted to the recipient's
     * X25519 public key (D-05). Mirrors PeerCatchUpExchanger's plain
     * array-with-'type'-key control-message idiom.
     *
     * SECURITY PRECONDITION (WR-07): `sodium_crypto_box_seal` provides
     * CONFIDENTIALITY but NO sender authentication — anyone who knows a
     * device's X25519 public key can craft a wrap sealing an attacker-chosen
     * epoch key to it. This wrap is therefore SAFE to trust on receipt ONLY
     * when the delivery channel has independently authenticated the sender as a
     * CONFIRMED peer. In this codebase that authentication is provided by the
     * Noise IK session in `SyncWebSocketHandler` (peer static key verified
     * against `DeviceRegistryService::deviceX25519Keys()`, confirmed-only,
     * before any blob is exchanged). `GdkEpochControlHandler::handle()` (the
     * receive side) MUST NOT be invoked for a wrap that did not arrive over
     * such an authenticated, confirmed-peer channel — the seal alone is not a
     * sufficient trust anchor. A future hardening adds an explicit Ed25519
     * sender signature over the wrap so the receiver can verify provenance
     * without relying on transport-layer authentication; until then the
     * authenticated-channel precondition is a hard requirement, not a
     * convenience.
     *
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

    /**
     * ADD-device analog of {@see rotateAndRevoke()}'s Steps 1-2 fan-out
     * (minus Steps 1-2 themselves): wrap EVERY epoch already in $userId's
     * keyring, sealed to $newDeviceRegistryId's confirmed X25519 public key,
     * and enqueue each on the ZK-pure RelayMailbox for pickup — the SAME
     * delivery primitive `rotateAndRevoke()` uses, reused unchanged. Added
     * for Phase 15 import-join (B1): the desktop must push its FULL epoch
     * history to a newly-confirmed phone, not just future rotations.
     *
     * NO rotation, NO revoke, NO `appendEpoch()`, NO `current_epoch` change
     * — this is purely additive delivery of ALREADY-EXISTING epochs. An
     * empty keyring (encryption never enabled on this desktop) enqueues
     * zero wraps — benign; the phone stays unencrypted-but-synced.
     *
     * ## Trust-gate ordering (critical, threat-model item 1)
     *
     * This method is SAFE to call only for an already-CONFIRMED recipient.
     * Defense-in-depth: it re-checks `confirmed_at IS NOT NULL` on the
     * recipient row itself and refuses (zero wraps, no exception, nothing
     * logged that could leak trust state) when the row is not confirmed OR
     * is the acting self device (no wrap-to-self) OR does not exist for
     * this user. The CALLER (`PairingFlowModal`, Task 3 UI half) is
     * additionally responsible for reaching this method ONLY from the
     * `state === CONFIRMED` branch returned by
     * `PairingTokenService::confirm()`'s `bothConfirmed()` transition — a
     * pending/awaiting/expired/rejected token must never reach this method
     * at all, so the two checks are independent, redundant gates on the
     * SAME invariant (T-15-17/T-15-19 preserved).
     *
     * @throws \LogicException when the app-lock KEK is unavailable
     *                         (propagated from `GdkKeyringService::loadKeyring()`).
     * @throws RuntimeException on a crypto / I-O failure while wrapping.
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

        // No wrap-to-self — mirrors rotateAndRevoke()'s Step 3 self-exclusion.
        if ((bool) $recipient->is_self === true) {
            return;
        }

        $recipientDeviceId = is_string($recipient->device_id) ? $recipient->device_id : null;
        $recipientPubHex = is_string($recipient->x25519_public_key_hex) ? $recipient->x25519_public_key_hex : null;

        if ($recipientDeviceId === null || $recipientPubHex === null) {
            return;
        }

        // loadKeyring() throws \LogicException when the KEK is unavailable
        // (D-02 weak-key-window guard) and returns an EMPTY keyring when
        // encryption was never enabled for this user (group-of-one
        // bootstrap posture, mirrors rotateAndRevoke()'s own tolerance) —
        // in the latter case the foreach below simply enqueues zero wraps.
        $keyring = $this->keyringService->loadKeyring($userId, $session);
        $selfDeviceId = $this->selfDeviceId($userId);
        $recipientPub = sodium_hex2bin($recipientPubHex);

        try {
            $connection->transaction(function () use ($keyring, $recipientPub, $recipientDeviceId, $selfDeviceId): void {
                foreach ($keyring->epochs() as $epoch) {
                    $rawKey = sodium_hex2bin($epoch->keyHex);

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
            throw new RuntimeException('GdkRotationService::fanOutAllEpochsToDevice — sodium error during fan-out.', 0, $e);
        }
    }

    /**
     * The acting device's own device_id (the `is_self = 1` row), or null if
     * this user somehow has no self row yet.
     */
    private function selfDeviceId(int $userId): ?string
    {
        $value = $this->db->connection()->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 1)
            ->value('device_id');

        return is_string($value) ? $value : null;
    }
}
