<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Public\Services\BlindIndexCodec;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;
use SodiumException;

final class GdkEpochControlHandler
{
    // The wrapped epoch key is an anonymous sodium_crypto_box_seal — confidential
    // but not sender-authenticated. handle() therefore verifies a detached
    // Ed25519 signature over the wrap against the sender's STILL-CONFIRMED device
    // key before opening it, so a forged wrap is refused however it arrived.

    public const string MSG_GDK_EPOCH_WRAP = 'GDK_EPOCH_WRAP';

    public function __construct(
        private readonly PeerCatchUpExchanger $catchUp,
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly GdkKeyringService $keyringService,
        private readonly GdkEpochUsageProbe $usageProbe,
        private readonly LoggerInterface $logger,
        private readonly DeviceRegistryService $deviceRegistry,
        private readonly DeviceKeySigner $signer,
        private readonly BlindIndexCodec $blindIndex,
    ) {}

    // Never throws on a malformed/tampered/foreign message — every rejection
    // path logs and returns an outcome. Deferred is the one the caller must
    // honour: it means the sealed box was never opened, so consuming the
    // mailbox row that carried it destroys the only copy of the key.
    /**
     * @param  string  $json  Raw control-message JSON (already Noise/relay
     *                        decrypted — this is the plaintext envelope
     *                        carrying the sealed-box-wrapped epoch key).
     * @param  int  $userId  Owner user — scopes the local keyring/identity lookups.
     * @param  Session  $session  Laravel session used to release the local
     *                            app-lock KEK (GdkKeyringService/DeviceIdentityLoader).
     */
    public function handle(string $json, int $userId, Session $session): GdkWrapOutcome
    {
        $wrap = $this->parseWrap($json);
        if ($wrap === null) {
            return GdkWrapOutcome::Refused;
        }

        // A key-file that exists but will not open is a locked device, not an
        // un-enrolled one. Folding the two into one null is what made a
        // deferrable wrap indistinguishable from a permanently foreign one.
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return $this->identityLoader->exists($userId)
                ? $this->deferred('no app-lock key in this process, so the sealed box cannot be opened')
                : $this->refused('sync was never enabled for this user');
        }

        if (! hash_equals($identity->deviceId, $wrap['recipientDeviceId'])) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP addressed to a different device — rejected.');

            return GdkWrapOutcome::Refused;
        }

        // Authenticate the SENDER before opening or appending: a wrap is adopted
        // only when its Ed25519 signature verifies against a device STILL
        // confirmed in this user's registry. Closes forged-epoch-key injection
        // from an untrusted relay or a revoked peer, whatever channel carried it.
        if (! $this->senderIsAuthentic($wrap, $userId)) {
            return GdkWrapOutcome::Refused;
        }

        return $this->openAndAppendEpoch($wrap, $identity, $userId, $session);
    }

    private function deferred(string $why): GdkWrapOutcome
    {
        $this->logger->info('GdkEpochControlHandler: GDK_EPOCH_WRAP deferred — '.$why.'.');

        return GdkWrapOutcome::Deferred;
    }

    private function refused(string $why): GdkWrapOutcome
    {
        $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP refused — '.$why.'.');

        return GdkWrapOutcome::Refused;
    }

    // The anti-forgery gate: the wrap's detached Ed25519 signature must verify
    // against the public key of a device STILL confirmed in this user's registry
    // (deviceKeys() filters on confirmed_at). An unconfirmed, unknown, or revoked
    // sender has no key here and is refused before any seal_open.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string, role: string, senderKeyed: bool}  $wrap
     */
    private function senderIsAuthentic(array $wrap, int $userId): bool
    {
        $senderPublicKeyBin = $this->confirmedSenderKey($wrap['senderDeviceId'], $userId);

        if ($senderPublicKeyBin === null) {
            return false;
        }

        $message = GdkEpochWrapSignature::signingMessage(
            $wrap['epochId'],
            $wrap['wrappedBin'],
            $wrap['recipientDeviceId'],
            $wrap['senderDeviceId'],
            $wrap['role'],
            $wrap['senderKeyed'],
        );

        if (! $this->signer->verify($message, $wrap['sigHex'], $senderPublicKeyBin)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP signature did not verify against the sender\'s confirmed key — rejected, no append.', [
                'sender_device_id' => $wrap['senderDeviceId'],
            ]);

            return false;
        }

        return true;
    }

    // The sender's public key in binary, or null when it cannot be trusted:
    // deviceKeys() filters on confirmed_at, so an unconfirmed, unknown or
    // revoked sender has no entry, and a stored key that is not valid hex is
    // no more usable than a missing one.
    private function confirmedSenderKey(string $senderDeviceId, int $userId): ?string
    {
        $senderPublicKeyHex = $this->deviceRegistry->deviceKeys($userId)[$senderDeviceId] ?? null;

        if (! is_string($senderPublicKeyHex) || $senderPublicKeyHex === '') {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP from an unconfirmed or unknown sender — rejected, no append.', [
                'sender_device_id' => $senderDeviceId,
            ]);

            return null;
        }

        try {
            return sodium_hex2bin($senderPublicKeyHex);
        } catch (SodiumException $e) {
            $this->logger->warning('GdkEpochControlHandler: sender device key is not valid hex — rejected.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    // Reads the control envelope, filters to GDK_EPOCH_WRAP, and validates its
    // fields — every rejection returns null so handle() stays a linear pipeline.
    /**
     * @return array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string, role: string, senderKeyed: bool}|null
     */
    private function parseWrap(string $json): ?array
    {
        $msg = $this->readEnvelope($json);
        if ($msg === null) {
            return null;
        }

        return $this->extractWrapFields($msg);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readEnvelope(string $json): ?array
    {
        try {
            $msg = $this->catchUp->parseControlMessage($json);
        } catch (\UnexpectedValueException $e) {
            $this->logger->warning('GdkEpochControlHandler: malformed control message rejected.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        // Not addressed to this handler — a fixed linear step, not a generic
        // dispatcher, so a non-matching type is simply not ours to handle.
        return ($msg['type'] ?? null) === self::MSG_GDK_EPOCH_WRAP ? $msg : null;
    }

    /**
     * @param  array<string, mixed>  $msg
     * @return array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string, role: string, senderKeyed: bool}|null
     */
    private function extractWrapFields(array $msg): ?array
    {
        $epochIdRaw = $msg['epoch_id'] ?? null;
        $wrappedB64 = $msg['wrapped_key_b64'] ?? null;
        $recipientDeviceId = $msg['recipient_device_id'] ?? null;
        $senderDeviceId = $msg['sender_device_id'] ?? null;
        $sigHex = $msg['sig_hex'] ?? null;
        $role = $msg['key_role'] ?? GdkEpochWrapSignature::ROLE_EPOCH;
        $senderKeyed = $msg['sender_holds_keyed_rows'] ?? false;

        if (! is_string($role) || ! in_array($role, [GdkEpochWrapSignature::ROLE_EPOCH, GdkEpochWrapSignature::ROLE_BLIND_INDEX], true)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP names an unknown key role — rejected.');

            return null;
        }

        if (! is_bool($senderKeyed)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP carries a non-boolean sender_holds_keyed_rows — rejected.');

            return null;
        }

        if (! is_int($epochIdRaw)
            || ! is_string($wrappedB64) || $wrappedB64 === ''
            || ! is_string($recipientDeviceId) || $recipientDeviceId === ''
            || ! is_string($senderDeviceId) || $senderDeviceId === ''
            || ! is_string($sigHex) || $sigHex === ''
        ) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP missing/malformed fields — rejected.');

            return null;
        }

        $wrappedBin = base64_decode($wrappedB64, true);
        if ($wrappedBin === false || $wrappedBin === '') {
            $this->logger->warning('GdkEpochControlHandler: wrapped_key_b64 is not valid base64 — rejected.');

            return null;
        }

        return [
            'epochId' => $epochIdRaw,
            'wrappedBin' => $wrappedBin,
            'recipientDeviceId' => $recipientDeviceId,
            'senderDeviceId' => $senderDeviceId,
            'sigHex' => $sigHex,
            'role' => $role,
            'senderKeyed' => $senderKeyed,
        ];
    }

    // Loads the keyring first so the key already held for this epoch id, if
    // any, travels into the open — the collision is settled by comparing
    // keys, which needs the wrap decrypted.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string, role: string, senderKeyed: bool}  $wrap
     */
    private function openAndAppendEpoch(array $wrap, DeviceIdentityDto $identity, int $userId, Session $session): GdkWrapOutcome
    {
        try {
            $existing = $this->keyringService->loadKeyring($userId, $session);
        } catch (\LogicException) {
            return $this->deferred('the app-lock is not unlocked, so the keyring cannot be read');
        }

        return $this->decryptAndStore($wrap, $identity, $userId, $existing->keyFor($wrap['epochId']), $session);
    }

    // Opens the sealed box under the local keypair and appends the recovered
    // epoch under the device's OWN KEK — the wire never supplies a key here.
    // Every branch zeroes the key material in the finally.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string, role: string, senderKeyed: bool}  $wrap
     * @param  string|null  $localKeyHex  The key already held for this epoch id, if any.
     */
    private function decryptAndStore(
        array $wrap,
        DeviceIdentityDto $identity,
        int $userId,
        ?string $localKeyHex,
        Session $session,
    ): GdkWrapOutcome {
        $localSecret = sodium_hex2bin($identity->x25519SecretKeyHex);
        $localPublic = sodium_hex2bin($identity->x25519PublicKeyHex);
        $localKeypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($localSecret, $localPublic);
        $rawKey = false;

        try {
            $rawKey = sodium_crypto_box_seal_open($wrap['wrappedBin'], $localKeypair);

            // Strict === false check, never `!$rawKey`, which would silently
            // accept a legitimate all-zero-byte key as if it were `false`.
            if ($rawKey === false) {
                $this->logger->warning('GdkEpochControlHandler: sodium_crypto_box_seal_open failed (tampered ciphertext or foreign recipient) — rejected, no append.');

                return GdkWrapOutcome::Refused;
            }

            if ($wrap['role'] === GdkEpochWrapSignature::ROLE_BLIND_INDEX) {
                return $this->storeBlindIndexKey(sodium_bin2hex($rawKey), $wrap['senderKeyed'], $userId, $session);
            }

            $epoch = new GdkEpoch(epochId: $wrap['epochId'], keyHex: sodium_bin2hex($rawKey));

            if ($localKeyHex !== null) {
                return $this->reconcileCollision($epoch, $localKeyHex, $userId, $wrap['recipientDeviceId'], $session);
            }

            $this->keyringService->appendEpoch($userId, $epoch, $session);

            return GdkWrapOutcome::Applied;
        } catch (SodiumException $e) {
            $this->logger->warning('GdkEpochControlHandler: sodium error while opening GDK_EPOCH_WRAP.', [
                'error' => $e->getMessage(),
            ]);

            return GdkWrapOutcome::Refused;
        } catch (\LogicException) {
            return $this->deferred('the app-lock is not unlocked, so the recovered epoch cannot be appended');
        } finally {
            if ($rawKey !== false) {
                sodium_memzero($rawKey);
            }
            sodium_memzero($localSecret);
            sodium_memzero($localPublic);
            sodium_memzero($localKeypair);
        }
    }

    // The blind-index key is not an epoch: it is never rotated, and one device
    // holding a different one means the group's stored counterparty digests
    // stop matching. A keyring holds many epochs, so the additive fan-out is
    // safe for them; this slot is single-valued, so arrival order must not decide it.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function storeBlindIndexKey(string $incomingKeyHex, bool $senderKeyed, int $userId, Session $session): GdkWrapOutcome
    {
        try {
            $localKeyHex = $this->keyringService->blindIndexKeyHex($userId, $session);

            if ($localKeyHex === null) {
                $this->keyringService->setBlindIndexKey($userId, $incomingKeyHex, $session);

                return GdkWrapOutcome::Applied;
            }

            if (hash_equals($localKeyHex, $incomingKeyHex)) {
                return GdkWrapOutcome::Applied;
            }

            if ($this->adoptsBlindIndexKey($localKeyHex, $incomingKeyHex, $senderKeyed, $userId)) {
                $this->keyringService->setBlindIndexKey($userId, $incomingKeyHex, $session);
            }

            // Applied either way: a decision was reached over the key the wrap
            // carried, so redelivering it would reach the same one.
            return GdkWrapOutcome::Applied;
        } catch (\LogicException) {
            return $this->deferred('the app-lock is not unlocked, so the recovered blind-index key cannot be stored');
        }
    }

    // Both sides run this over the same two keys and the same two keyed-flags,
    // so they reach the same answer whichever wrap lands first.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function adoptsBlindIndexKey(string $localKeyHex, string $incomingKeyHex, bool $senderKeyed, int $userId): bool
    {
        $localKeyed = $this->blindIndex->holdsDerivedRows($userId);

        if ($localKeyed && $senderKeyed) {
            // Neither side can give way without orphaning its own digests, so
            // both keep what they have and the divergence is raised rather
            // than half-resolved into a doubled ledger on one of them.
            $this->logger->error('GdkEpochControlHandler: both this device and the peer already hold rows keyed under different blind-index keys — keeping the local key; merchant identity will not match across the two devices until one side is re-derived.', [
                'user_id' => $userId,
            ]);

            return false;
        }

        // Whichever side holds keyed rows wins, because it is the side with
        // something to lose. The peer is running this same branch inverted.
        if ($localKeyed !== $senderKeyed) {
            return $senderKeyed;
        }

        // Neither holds keyed rows, so nothing is at stake and only agreement
        // matters. Lowest hex wins: an order both sides compute identically.
        return strcmp($incomingKeyHex, $localKeyHex) < 0;
    }

    // Settles an inbound epoch whose id this device already holds. An equal
    // key is an ordinary duplicate. A DIFFERENT one means both sides minted
    // the id independently: the peer's is the group's, and dropping it left
    // every row encrypted under it permanently unreadable.
    private function reconcileCollision(
        GdkEpoch $epoch,
        string $localKeyHex,
        int $userId,
        string $recipientDeviceId,
        Session $session,
    ): GdkWrapOutcome {
        if (hash_equals($localKeyHex, $epoch->keyHex)) {
            return GdkWrapOutcome::Applied;
        }

        // Local rows encrypted under this id would become unreadable if the
        // key went away, so the local key stays and the conflict is raised
        // instead of being silently resolved either way.
        if ($this->usageProbe->hasLocalEntriesAt($userId, $epoch->epochId)) {
            $this->logger->error('GdkEpochControlHandler: GDK epoch id collides with a locally-USED epoch — keeping the local key; peer rows at this epoch cannot be decrypted.', [
                'epoch_id' => $epoch->epochId,
                'recipient_device_id' => $recipientDeviceId,
            ]);

            return GdkWrapOutcome::Applied;
        }

        $this->keyringService->replaceEpoch($userId, $epoch, $session);

        $this->logger->warning('GdkEpochControlHandler: adopted the peer GDK epoch over an unused local key of the same id.', [
            'epoch_id' => $epoch->epochId,
            'recipient_device_id' => $recipientDeviceId,
        ]);

        return GdkWrapOutcome::Applied;
    }
}
