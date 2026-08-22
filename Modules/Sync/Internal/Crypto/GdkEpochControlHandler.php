<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
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
        private readonly LocallyKeyedRowsProbe $keyedRows,
    ) {}

    // Never throws on a malformed/tampered/foreign message — every rejection
    // path logs and returns an outcome. Only Applied and Refused let the
    // carrier be retired; the other two mean the sealed box was never opened
    // or its key is still needed, so consuming it destroys the only copy.
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
        $msg = $this->readEnvelope($json);
        if ($msg === null) {
            return GdkWrapOutcome::Refused;
        }

        $wrap = $this->extractWrapFields($msg);

        // A stored blob some other party mutated reads exactly like one a
        // later build wrote in a shape this build cannot parse, and retiring
        // the first destroys a key nothing re-sends.
        return $wrap === null
            ? $this->deferred('the wrap envelope could not be read')
            : $this->admitAndApply($wrap, $userId, $session);
    }

    // A key-file that exists but will not open is a locked device, not an
    // un-enrolled one. Folding the two into one null is what made a
    // deferrable wrap indistinguishable from a permanently foreign one.
    private function admitAndApply(GdkWrapEnvelope $wrap, int $userId, Session $session): GdkWrapOutcome
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            return $this->identityLoader->exists($userId)
                ? $this->deferred('no app-lock key in this process, so the sealed box cannot be opened')
                : $this->refused('sync was never enabled for this user');
        }

        if (! hash_equals($identity->deviceId, $wrap->recipientDeviceId)) {
            return $this->refused('the wrap is addressed to a different device');
        }

        // Authenticate the SENDER before opening or appending: a wrap is adopted
        // only when its Ed25519 signature verifies against a device STILL
        // confirmed in this user's registry. Closes forged-epoch-key injection
        // from an untrusted relay or a revoked peer, whatever channel carried it.
        $rejection = $this->senderRejection($wrap, $userId) ?? $this->roleRejection($wrap);

        return $rejection ?? $this->openAndAppendEpoch($wrap, $identity, $userId, $session);
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

    // Null when the sender is authentic. A sender this device does not yet
    // confirm is an ORDERING fact, not a permanent one — during pairing the
    // wrap can outrun the registry row — so it defers; only a signature that
    // fails against a key this device does trust is terminal.
    private function senderRejection(GdkWrapEnvelope $wrap, int $userId): ?GdkWrapOutcome
    {
        $senderPublicKeyBin = $this->confirmedSenderKey($wrap->senderDeviceId, $userId);

        if ($senderPublicKeyBin === null) {
            return $this->deferred('the sender is not a confirmed device on this device yet');
        }

        $message = GdkEpochWrapSignature::signingMessage(
            $wrap->epochId,
            $wrap->wrappedBin,
            $wrap->recipientDeviceId,
            $wrap->senderDeviceId,
            $wrap->role,
            $wrap->senderKeyed,
        );

        if (! $this->signer->verify($message, $wrap->sigHex, $senderPublicKeyBin)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP signature did not verify against the sender\'s confirmed key — rejected, no append.', [
                'sender_device_id' => $wrap->senderDeviceId,
            ]);

            return GdkWrapOutcome::Refused;
        }

        return null;
    }

    // Judged only AFTER the signature verified, so an unknown role names a
    // build ahead of this one rather than a byte somebody flipped in storage.
    // The reserved epoch id is signed too, so a blind-index wrap carrying any
    // other one was produced that way and will never become valid.
    private function roleRejection(GdkWrapEnvelope $wrap): ?GdkWrapOutcome
    {
        if (! in_array($wrap->role, [GdkEpochWrapSignature::ROLE_EPOCH, GdkEpochWrapSignature::ROLE_BLIND_INDEX], true)) {
            return $this->deferred('the wrap names a key role this build does not know');
        }

        if ($wrap->role === GdkEpochWrapSignature::ROLE_BLIND_INDEX
            && $wrap->epochId !== GdkEpochWrapSignature::BLIND_INDEX_EPOCH_ID) {
            return $this->refused('a blind-index wrap carries an epoch id other than the reserved one');
        }

        return null;
    }

    // The sender's public key in binary, or null when it cannot be trusted:
    // deviceKeys() filters on confirmed_at, so an unconfirmed, unknown or
    // revoked sender has no entry, and a stored key that is not valid hex is
    // no more usable than a missing one.
    private function confirmedSenderKey(string $senderDeviceId, int $userId): ?string
    {
        $senderPublicKeyHex = $this->deviceRegistry->deviceKeys($userId)[$senderDeviceId] ?? null;

        if (! is_string($senderPublicKeyHex) || $senderPublicKeyHex === '') {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP from an unconfirmed or unknown sender — held, no append.', [
                'sender_device_id' => $senderDeviceId,
            ]);

            return null;
        }

        try {
            return sodium_hex2bin($senderPublicKeyHex);
        } catch (SodiumException $e) {
            $this->logger->warning('GdkEpochControlHandler: sender device key is not valid hex — held, no append.', [
                'error' => $e->getMessage(),
            ]);

            return null;
        }
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

    // Reads the envelope's fields WITHOUT judging what they name: an allowlist
    // applied here would be fatal to a value no signature covers, which let a
    // single appended JSON key retire an epoch key that was never in doubt.
    /**
     * @param  array<string, mixed>  $msg
     */
    private function extractWrapFields(array $msg): ?GdkWrapEnvelope
    {
        $role = $msg['key_role'] ?? GdkEpochWrapSignature::ROLE_EPOCH;

        if (! is_string($role)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP carries a non-string key_role — held.');

            return null;
        }

        $senderKeyed = $this->senderKeyedFlag($msg, $role);
        if ($senderKeyed === null) {
            return null;
        }

        return $this->sealedWrapFrom($msg, $role, $senderKeyed);
    }

    // The five fields every wrap carries, whatever its role, plus the sealed
    // bytes decoded. Null on any one of them being absent or unreadable.
    /**
     * @param  array<string, mixed>  $msg
     */
    private function sealedWrapFrom(array $msg, string $role, bool $senderKeyed): ?GdkWrapEnvelope
    {
        $epochIdRaw = $msg['epoch_id'] ?? null;
        $wrappedB64 = $msg['wrapped_key_b64'] ?? null;
        $recipientDeviceId = $msg['recipient_device_id'] ?? null;
        $senderDeviceId = $msg['sender_device_id'] ?? null;
        $sigHex = $msg['sig_hex'] ?? null;

        if (! is_int($epochIdRaw)
            || ! is_string($wrappedB64) || $wrappedB64 === ''
            || ! is_string($recipientDeviceId) || $recipientDeviceId === ''
            || ! is_string($senderDeviceId) || $senderDeviceId === ''
            || ! is_string($sigHex) || $sigHex === ''
        ) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP missing/malformed fields — held.');

            return null;
        }

        $wrappedBin = base64_decode($wrappedB64, true);
        if ($wrappedBin === false || $wrappedBin === '') {
            $this->logger->warning('GdkEpochControlHandler: wrapped_key_b64 is not valid base64 — held.');

            return null;
        }

        return new GdkWrapEnvelope(
            epochId: $epochIdRaw,
            wrappedBin: $wrappedBin,
            recipientDeviceId: $recipientDeviceId,
            senderDeviceId: $senderDeviceId,
            sigHex: $sigHex,
            role: $role,
            senderKeyed: $senderKeyed,
        );
    }

    // An epoch wrap has no such field and never signs one, so reading it there
    // would make a value outside the signature decide the message's fate.
    // Null means unreadable, which the caller holds rather than retires.
    /**
     * @param  array<string, mixed>  $msg
     */
    private function senderKeyedFlag(array $msg, string $role): ?bool
    {
        if ($role !== GdkEpochWrapSignature::ROLE_BLIND_INDEX) {
            return false;
        }

        $senderKeyed = $msg['sender_holds_keyed_rows'] ?? false;

        if (! is_bool($senderKeyed)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP carries a non-boolean sender_holds_keyed_rows — held.');

            return null;
        }

        return $senderKeyed;
    }

    // Loads the keyring first so the key already held for this epoch id, if
    // any, travels into the open — the collision is settled by comparing
    // keys, which needs the wrap decrypted.
    private function openAndAppendEpoch(GdkWrapEnvelope $wrap, DeviceIdentityDto $identity, int $userId, Session $session): GdkWrapOutcome
    {
        try {
            $existing = $this->keyringService->loadKeyring($userId, $session);
        } catch (\LogicException) {
            return $this->deferred('the app-lock is not unlocked, so the keyring cannot be read');
        }

        return $this->decryptAndStore($wrap, $identity, $userId, $existing->keyFor($wrap->epochId), $session);
    }

    // Opens the sealed box under the local keypair and hands the recovered key
    // on to be stored under the device's OWN KEK — the wire never supplies a
    // key here. Every branch zeroes the key material in the finally.
    /**
     * @param  string|null  $localKeyHex  The key already held for this epoch id, if any.
     */
    private function decryptAndStore(
        GdkWrapEnvelope $wrap,
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
            $rawKey = sodium_crypto_box_seal_open($wrap->wrappedBin, $localKeypair);

            // Strict === false check, never `!$rawKey`, which would silently
            // accept a legitimate all-zero-byte key as if it were `false`.
            return $rawKey === false
                ? $this->refused('the sealed box did not open (tampered ciphertext or foreign recipient)')
                : $this->storeRecoveredKey($wrap, $rawKey, $identity, $userId, $localKeyHex, $session);
        } catch (SodiumException $e) {
            $this->logger->warning('GdkEpochControlHandler: sodium error while opening GDK_EPOCH_WRAP.', [
                'error' => $e->getMessage(),
            ]);

            return $this->deferred('a libsodium failure interrupted the open, which the next attempt may not hit');
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

    // Decides where the opened key belongs; zeroing it stays the caller's, in
    // the finally this runs inside. A blind-index key is not an epoch and
    // never enters the epoch list, whatever epoch id its envelope carried.
    private function storeRecoveredKey(
        GdkWrapEnvelope $wrap,
        string $rawKey,
        DeviceIdentityDto $identity,
        int $userId,
        ?string $localKeyHex,
        Session $session,
    ): GdkWrapOutcome {
        if ($wrap->role === GdkEpochWrapSignature::ROLE_BLIND_INDEX) {
            return $this->storeBlindIndexKey(sodium_bin2hex($rawKey), $wrap->senderKeyed, $identity->deviceId, $userId, $session);
        }

        $epoch = new GdkEpoch(epochId: $wrap->epochId, keyHex: sodium_bin2hex($rawKey));

        if ($localKeyHex !== null) {
            return $this->reconcileCollision($epoch, $localKeyHex, $userId, $wrap->recipientDeviceId, $session);
        }

        $this->keyringService->appendEpoch($userId, $epoch, $session);

        return GdkWrapOutcome::Applied;
    }

    // The blind-index key is not an epoch: it is never rotated, and one device
    // holding a different one means the group's stored counterparty digests
    // stop matching. A keyring holds many epochs, so the additive fan-out is
    // safe for them; this slot is single-valued, so arrival order must not decide it.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function storeBlindIndexKey(
        string $incomingKeyHex,
        bool $senderKeyed,
        string $localDeviceId,
        int $userId,
        Session $session,
    ): GdkWrapOutcome {
        try {
            $localKeyHex = $this->keyringService->blindIndexKeyHex($userId, $session);

            if ($localKeyHex === null) {
                $this->keyringService->setBlindIndexKey($userId, $incomingKeyHex, $session);

                return GdkWrapOutcome::Applied;
            }

            return $this->reconcileBlindIndexKeys($localKeyHex, $incomingKeyHex, $senderKeyed, $localDeviceId, $userId, $session);
        } catch (\LogicException) {
            return $this->deferred('the app-lock is not unlocked, so the recovered blind-index key cannot be stored');
        }
    }

    // Settles an incoming index key against a DIFFERENT one this device holds.
    // Retained, not retired, when the local key wins: the wrap is the only copy
    // of the peer's index key, and re-deriving one side's rows onto the other
    // is what a recovery would need it for.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function reconcileBlindIndexKeys(
        string $localKeyHex,
        string $incomingKeyHex,
        bool $senderKeyed,
        string $localDeviceId,
        int $userId,
        Session $session,
    ): GdkWrapOutcome {
        if (hash_equals($localKeyHex, $incomingKeyHex)) {
            return GdkWrapOutcome::Applied;
        }

        if (! $this->adoptsBlindIndexKey($localKeyHex, $incomingKeyHex, $senderKeyed, $localDeviceId, $userId, $session)) {
            return GdkWrapOutcome::Retained;
        }

        $this->keyringService->setBlindIndexKey($userId, $incomingKeyHex, $session);

        return GdkWrapOutcome::Applied;
    }

    // Both sides send, and both run this over the same two keys and the same
    // two keyed-flags, so they reach the same answer whichever wrap lands first.
    /**
     * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md
     */
    private function adoptsBlindIndexKey(
        string $localKeyHex,
        string $incomingKeyHex,
        bool $senderKeyed,
        string $localDeviceId,
        int $userId,
        Session $session,
    ): bool {
        $localKeyed = $this->keyedRows->holdsRowsKeyedUnder($userId, $localDeviceId, $localKeyHex, $session);

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
        // something to lose. The peer runs this same branch inverted over the
        // wrap this device sent it.
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

            return GdkWrapOutcome::Retained;
        }

        $this->keyringService->replaceEpoch($userId, $epoch, $session);

        $this->logger->warning('GdkEpochControlHandler: adopted the peer GDK epoch over an unused local key of the same id.', [
            'epoch_id' => $epoch->epochId,
            'recipient_device_id' => $recipientDeviceId,
        ]);

        return GdkWrapOutcome::Applied;
    }
}
