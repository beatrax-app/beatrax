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
    ) {}

    // Never throws on a malformed/tampered/foreign message — every rejection
    // path logs and returns.
    /**
     * @param  string  $json  Raw control-message JSON (already Noise/relay
     *                        decrypted — this is the plaintext envelope
     *                        carrying the sealed-box-wrapped epoch key).
     * @param  int  $userId  Owner user — scopes the local keyring/identity lookups.
     * @param  Session  $session  Laravel session used to release the local
     *                            app-lock KEK (GdkKeyringService/DeviceIdentityLoader).
     */
    public function handle(string $json, int $userId, Session $session): void
    {
        $wrap = $this->parseWrap($json);
        if ($wrap === null) {
            return;
        }

        $identity = $this->resolveRecipientIdentity($userId, $wrap['recipientDeviceId'], $session);
        if ($identity === null) {
            return;
        }

        // Authenticate the SENDER before opening or appending: a wrap is adopted
        // only when its Ed25519 signature verifies against a device STILL
        // confirmed in this user's registry. Closes forged-epoch-key injection
        // from an untrusted relay or a revoked peer, whatever channel carried it.
        if (! $this->senderIsAuthentic($wrap, $userId)) {
            return;
        }

        $this->openAndAppendEpoch($wrap, $identity, $userId, $session);
    }

    // The anti-forgery gate: the wrap's detached Ed25519 signature must verify
    // against the public key of a device STILL confirmed in this user's registry
    // (deviceKeys() filters on confirmed_at). An unconfirmed, unknown, or revoked
    // sender has no key here and is refused before any seal_open.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string}  $wrap
     */
    private function senderIsAuthentic(array $wrap, int $userId): bool
    {
        $senderPublicKeyHex = $this->deviceRegistry->deviceKeys($userId)[$wrap['senderDeviceId']] ?? null;
        if (! is_string($senderPublicKeyHex) || $senderPublicKeyHex === '') {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP from an unconfirmed or unknown sender — rejected, no append.', [
                'sender_device_id' => $wrap['senderDeviceId'],
            ]);

            return false;
        }

        try {
            $senderPublicKeyBin = sodium_hex2bin($senderPublicKeyHex);
        } catch (SodiumException $e) {
            $this->logger->warning('GdkEpochControlHandler: sender device key is not valid hex — rejected.', [
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        $message = GdkEpochWrapSignature::signingMessage(
            $wrap['epochId'],
            $wrap['wrappedBin'],
            $wrap['recipientDeviceId'],
            $wrap['senderDeviceId'],
        );

        if (! $this->signer->verify($message, $wrap['sigHex'], $senderPublicKeyBin)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP signature did not verify against the sender\'s confirmed key — rejected, no append.', [
                'sender_device_id' => $wrap['senderDeviceId'],
            ]);

            return false;
        }

        return true;
    }

    // Reads the control envelope, filters to GDK_EPOCH_WRAP, and validates its
    // fields — every rejection returns null so handle() stays a linear pipeline.
    /**
     * @return array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string}|null
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
     * @return array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string}|null
     */
    private function extractWrapFields(array $msg): ?array
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
        ];
    }

    // Loads the local identity and confirms the wrap is addressed to THIS
    // device before any sodium call — a wrap for another device would fail
    // seal_open anyway, but this rejects it up front.
    private function resolveRecipientIdentity(int $userId, string $recipientDeviceId, Session $session): ?DeviceIdentityDto
    {
        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->logger->warning('GdkEpochControlHandler: no local device identity available (app locked or sync never enabled) — rejected.');

            return null;
        }

        if (! hash_equals($identity->deviceId, $recipientDeviceId)) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP addressed to a different device — rejected.');

            return null;
        }

        return $identity;
    }

    // Loads the keyring first so the key already held for this epoch id, if
    // any, travels into the open — the collision is settled by comparing
    // keys, which needs the wrap decrypted.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string}  $wrap
     */
    private function openAndAppendEpoch(array $wrap, DeviceIdentityDto $identity, int $userId, Session $session): void
    {
        try {
            $existing = $this->keyringService->loadKeyring($userId, $session);
        } catch (\LogicException $e) {
            $this->logger->warning('GdkEpochControlHandler: app-lock not unlocked — cannot process GDK_EPOCH_WRAP right now.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        $this->decryptAndStore($wrap, $identity, $userId, $existing->keyFor($wrap['epochId']), $session);
    }

    // Opens the sealed box under the local keypair and appends the recovered
    // epoch under the device's OWN KEK — the wire never supplies a key here.
    // Every branch zeroes the key material in the finally.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string, senderDeviceId: string, sigHex: string}  $wrap
     * @param  string|null  $localKeyHex  The key already held for this epoch id, if any.
     */
    private function decryptAndStore(
        array $wrap,
        DeviceIdentityDto $identity,
        int $userId,
        ?string $localKeyHex,
        Session $session,
    ): void {
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

                return;
            }

            $epoch = new GdkEpoch(epochId: $wrap['epochId'], keyHex: sodium_bin2hex($rawKey));

            if ($localKeyHex !== null) {
                $this->reconcileCollision($epoch, $localKeyHex, $userId, $wrap['recipientDeviceId'], $session);

                return;
            }

            $this->keyringService->appendEpoch($userId, $epoch, $session);
        } catch (SodiumException $e) {
            $this->logger->warning('GdkEpochControlHandler: sodium error while opening GDK_EPOCH_WRAP.', [
                'error' => $e->getMessage(),
            ]);
        } catch (\LogicException $e) {
            $this->logger->warning('GdkEpochControlHandler: app-lock not unlocked — cannot append recovered GDK epoch.', [
                'error' => $e->getMessage(),
            ]);
        } finally {
            if ($rawKey !== false) {
                sodium_memzero($rawKey);
            }
            sodium_memzero($localSecret);
            sodium_memzero($localPublic);
            sodium_memzero($localKeypair);
        }
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
    ): void {
        if (hash_equals($localKeyHex, $epoch->keyHex)) {
            return;
        }

        // Local rows encrypted under this id would become unreadable if the
        // key went away, so the local key stays and the conflict is raised
        // instead of being silently resolved either way.
        if ($this->usageProbe->hasLocalEntriesAt($userId, $epoch->epochId)) {
            $this->logger->error('GdkEpochControlHandler: GDK epoch id collides with a locally-USED epoch — keeping the local key; peer rows at this epoch cannot be decrypted.', [
                'epoch_id' => $epoch->epochId,
                'recipient_device_id' => $recipientDeviceId,
            ]);

            return;
        }

        $this->keyringService->replaceEpoch($userId, $epoch, $session);

        $this->logger->warning('GdkEpochControlHandler: adopted the peer GDK epoch over an unused local key of the same id.', [
            'epoch_id' => $epoch->epochId,
            'recipient_device_id' => $recipientDeviceId,
        ]);
    }
}
