<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Psr\Log\LoggerInterface;
use SodiumException;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class GdkEpochControlHandler
{
    // SECURITY PRECONDITION: the wrapped epoch key travels as an anonymous
    // sodium_crypto_box_seal — confidential but unauthenticated. handle() MUST
    // only be called with a $json envelope that arrived over a channel that
    // has already authenticated the sender as a CONFIRMED peer (see @link).

    public const string MSG_GDK_EPOCH_WRAP = 'GDK_EPOCH_WRAP';

    public function __construct(
        private readonly PeerCatchUpExchanger $catchUp,
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly GdkKeyringService $keyringService,
        private readonly LoggerInterface $logger,
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

        $this->openAndAppendEpoch($wrap, $identity, $userId, $session);
    }

    // Reads the control envelope, filters to GDK_EPOCH_WRAP, and validates its
    // fields — every rejection returns null so handle() stays a linear pipeline.
    /**
     * @return array{epochId: int, wrappedBin: string, recipientDeviceId: string}|null
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
     * @return array{epochId: int, wrappedBin: string, recipientDeviceId: string}|null
     */
    private function extractWrapFields(array $msg): ?array
    {
        $epochIdRaw = $msg['epoch_id'] ?? null;
        $wrappedB64 = $msg['wrapped_key_b64'] ?? null;
        $recipientDeviceId = $msg['recipient_device_id'] ?? null;

        if (! is_int($epochIdRaw)
            || ! is_string($wrappedB64) || $wrappedB64 === ''
            || ! is_string($recipientDeviceId) || $recipientDeviceId === ''
        ) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP missing/malformed fields — rejected.');

            return null;
        }

        $wrappedBin = base64_decode($wrappedB64, true);
        if ($wrappedBin === false || $wrappedBin === '') {
            $this->logger->warning('GdkEpochControlHandler: wrapped_key_b64 is not valid base64 — rejected.');

            return null;
        }

        return ['epochId' => $epochIdRaw, 'wrappedBin' => $wrappedBin, 'recipientDeviceId' => $recipientDeviceId];
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

    // Guards the keyring load and the epoch-already-present idempotency check
    // before any decryption is attempted, so a duplicate wrap never re-opens.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string}  $wrap
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

        // An already-present epoch is never re-appended. This also covers a
        // desktop ADD-device fan-out self-collision (a self-minting device
        // already holding its own epoch 1 under a different key) — logged
        // with device/epoch ids only, never key material.
        if ($existing->keyFor($wrap['epochId']) !== null) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP epoch_id already present locally — colliding delivery dropped (idempotency guard).', [
                'epoch_id' => $wrap['epochId'],
                'recipient_device_id' => $wrap['recipientDeviceId'],
            ]);

            return;
        }

        $this->decryptAndStore($wrap, $identity, $userId, $session);
    }

    // Opens the sealed box under the local keypair and appends the recovered
    // epoch under the device's OWN KEK — the wire never supplies a key here.
    // Every branch zeroes the key material in the finally.
    /**
     * @param  array{epochId: int, wrappedBin: string, recipientDeviceId: string}  $wrap
     */
    private function decryptAndStore(array $wrap, DeviceIdentityDto $identity, int $userId, Session $session): void
    {
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
}
