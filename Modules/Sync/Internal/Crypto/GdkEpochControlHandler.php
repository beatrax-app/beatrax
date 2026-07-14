<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Crypto;

use Illuminate\Contracts\Session\Session;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Psr\Log\LoggerInterface;
use SodiumException;

/**
 * Receive side of CRYPT-02's distribution (D-05): validate, sealed-box-open,
 * and append an inbound `GDK_EPOCH_WRAP` control message to the LOCAL
 * device's GDK keyring.
 *
 * ## Placement (Crypto namespace, not Transport)
 *
 * `SyncServiceProvider` already forward-registers this class under
 * `Modules\Sync\Internal\Crypto\GdkEpochControlHandler` (Plan 02's
 * single-owner forward-registration block: `$cryptoNamespace =
 * 'Modules\Sync\Internal\Crypto\\'; $this->singletonIfExists($cryptoNamespace
 * .'GdkEpochControlHandler');`). SyncServiceProvider is single-owner —
 * downstream plans (05/07/08) create classes only and never edit that
 * provider (STATE [11-02] precedent). This class therefore lives in the
 * `Crypto` namespace so the existing forward-registration actually binds it,
 * rather than adding a second, unwired copy under `Transport`.
 *
 * ## Validation-before-crypto (V5, T-14-16)
 *
 * `PeerCatchUpExchanger::parseControlMessage()` is reused verbatim for the
 * generic "valid JSON object with a string `type` field" envelope check —
 * mirroring the existing `CATCH_UP_REQUEST`/`CATCH_UP_RESPONSE` idiom before
 * this class does any further type-checked field extraction. No sodium call
 * ever touches attacker-influenced bytes until every field has been
 * type-checked AND the `recipient_device_id` has been confirmed to match
 * this device's own identity.
 *
 * ## SECURITY PRECONDITION: authenticated sender required (WR-07)
 *
 * The wrapped epoch key travels as an anonymous `sodium_crypto_box_seal` —
 * confidential but UNauthenticated: anyone who knows this device's X25519
 * public key can craft a `GDK_EPOCH_WRAP` sealing an attacker-chosen key to
 * it, and (with a not-yet-present, higher `epoch_id`) drive
 * `GdkKeyringService::appendEpoch()`'s unconditional `current_epoch` advance
 * so future writes encrypt under the attacker's key. The seal opening +
 * `recipient_device_id` match here therefore do NOT by themselves establish
 * trust. `handle()` MUST only be called with a `$json` envelope that arrived
 * over a channel that has already authenticated the sender as a CONFIRMED
 * peer — in this codebase, the Noise IK session in `SyncWebSocketHandler`
 * (peer static key verified against the confirmed-only
 * `DeviceRegistryService::deviceX25519Keys()` before any blob is exchanged).
 * The relay-mailbox drain in `SyncWebSocketHandler::deliverGdkEpochWraps()`
 * runs only after that handshake succeeds. Do NOT wire a new caller that
 * routes an unauthenticated (e.g. raw relay-pushed) blob into this method.
 * A future hardening adds an explicit Ed25519 sender signature over the wrap
 * so provenance is verifiable independent of the transport; until then the
 * authenticated-channel precondition above is a hard requirement.
 *
 * ## False-not-garbage (Pitfall 5)
 *
 * `sodium_crypto_box_seal_open()`'s return is checked with a strict
 * `=== false` — never a truthy/`!$x` check — and a failure REJECTS the
 * message (log + return, no throw, no append). The recovered raw key is
 * appended to the local keyring under the LOCAL device's own KEK
 * (`GdkKeyringService::appendEpoch()` — never a wire-supplied key).
 *
 * ## Idempotency (T-14-17)
 *
 * An `epoch_id` already present in the local keyring is never re-appended —
 * this both avoids a duplicate keyring entry and prevents
 * `GdkKeyringService::appendEpoch()`'s unconditional `current_epoch` advance
 * from ever downgrading an already-higher current epoch via a redelivered
 * (or replayed) stale wrap.
 *
 * ## Graceful degradation when the app is locked
 *
 * `GdkKeyringService`'s KEK-null guard (`\LogicException`) and
 * `DeviceIdentityLoader::load()`'s "no identity / app locked" `null` are
 * both treated as "cannot process this delivery right now" — logged and
 * returned, never thrown onward. This mirrors `OpLogReplayer`'s established
 * "headless daemon may have no unlocked session" degrade-gracefully
 * convention (STATE [Phase 13]) rather than tearing down the whole sync
 * session over one skippable delivery.
 */
final class GdkEpochControlHandler
{
    public const string MSG_GDK_EPOCH_WRAP = 'GDK_EPOCH_WRAP';

    public function __construct(
        private readonly PeerCatchUpExchanger $catchUp,
        private readonly DeviceIdentityLoader $identityLoader,
        private readonly GdkKeyringService $keyringService,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Parse, validate, open, and append one inbound `GDK_EPOCH_WRAP`
     * control message. Never throws on a malformed/tampered/foreign message
     * — every rejection path logs and returns.
     *
     * @param  string  $json  Raw control-message JSON (already Noise/relay
     *                        decrypted — this is the plaintext envelope
     *                        carrying the sealed-box-wrapped epoch key).
     * @param  int  $userId  Owner user — scopes the local keyring/identity lookups.
     * @param  Session  $session  Laravel session used to release the local
     *                            app-lock KEK (GdkKeyringService/DeviceIdentityLoader).
     */
    public function handle(string $json, int $userId, Session $session): void
    {
        try {
            $msg = $this->catchUp->parseControlMessage($json);
        } catch (\UnexpectedValueException $e) {
            $this->logger->warning('GdkEpochControlHandler: malformed control message rejected.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (($msg['type'] ?? null) !== self::MSG_GDK_EPOCH_WRAP) {
            // Not addressed to this handler — a fixed linear step, not a
            // generic dispatcher (14-PATTERNS.md "No Analog Found"), so a
            // non-matching type is simply not ours to handle.
            return;
        }

        $epochIdRaw = $msg['epoch_id'] ?? null;
        $wrappedB64 = $msg['wrapped_key_b64'] ?? null;
        $recipientDeviceId = $msg['recipient_device_id'] ?? null;

        if (! is_int($epochIdRaw)
            || ! is_string($wrappedB64) || $wrappedB64 === ''
            || ! is_string($recipientDeviceId) || $recipientDeviceId === ''
        ) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP missing/malformed fields — rejected.');

            return;
        }

        $identity = $this->identityLoader->load($userId, $session);
        if ($identity === null) {
            $this->logger->warning('GdkEpochControlHandler: no local device identity available (app locked or sync never enabled) — rejected.');

            return;
        }

        if (! hash_equals($identity->deviceId, $recipientDeviceId)) {
            // T-14-16: a wrap addressed to a different device must never be
            // opened here — sodium_crypto_box_seal_open would fail anyway
            // (wrong keypair), but this check rejects BEFORE any sodium call.
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP addressed to a different device — rejected.');

            return;
        }

        $wrappedBin = base64_decode($wrappedB64, true);
        if ($wrappedBin === false || $wrappedBin === '') {
            $this->logger->warning('GdkEpochControlHandler: wrapped_key_b64 is not valid base64 — rejected.');

            return;
        }

        try {
            $existing = $this->keyringService->loadKeyring($userId, $session);
        } catch (\LogicException $e) {
            $this->logger->warning('GdkEpochControlHandler: app-lock not unlocked — cannot process GDK_EPOCH_WRAP right now.', [
                'error' => $e->getMessage(),
            ]);

            return;
        }

        // T-14-17 idempotency: an already-present epoch is never re-appended.
        //
        // MEDIUM-02 fix (15-import-join-REVIEW.md): this is also the exact
        // point where a NORMAL (non-import) desktop ADD-device fan-out
        // silently collides — PairingFlowModal::fanOutToNewlyConfirmedDevice()
        // fires on EVERY confirmed peer, including a self-minting one that
        // already holds its OWN epoch 1 under a DIFFERENT key. Before this
        // fix that collision was dropped with NO log line at all — the
        // recipient could never decrypt the sender's epoch-1 history and
        // nothing surfaced the gap. A distinct warning (never key material
        // — device/epoch ids only) makes the drop observable without
        // requiring the desktop to know the recipient's remote keyring
        // state (a genuinely separate device database — see HIGH-01, out
        // of scope for this fix).
        if ($existing->keyFor($epochIdRaw) !== null) {
            $this->logger->warning('GdkEpochControlHandler: GDK_EPOCH_WRAP epoch_id already present locally — colliding delivery dropped (idempotency guard).', [
                'epoch_id' => $epochIdRaw,
                'recipient_device_id' => $recipientDeviceId,
            ]);

            return;
        }

        $localSecret = sodium_hex2bin($identity->x25519SecretKeyHex);
        $localPublic = sodium_hex2bin($identity->x25519PublicKeyHex);
        $localKeypair = sodium_crypto_box_keypair_from_secretkey_and_publickey($localSecret, $localPublic);
        $rawKey = false;

        try {
            $rawKey = sodium_crypto_box_seal_open($wrappedBin, $localKeypair);

            // Pitfall 5 — "false not garbage": a strict === false check,
            // never `!$rawKey`, which would silently accept a legitimate
            // all-zero-byte key as if it were `false`.
            if ($rawKey === false) {
                $this->logger->warning('GdkEpochControlHandler: sodium_crypto_box_seal_open failed (tampered ciphertext or foreign recipient) — rejected, no append.');

                return;
            }

            // Recovered epoch is appended under the LOCAL device's OWN KEK —
            // GdkKeyringService::appendEpoch() releases the KEK itself via
            // AppLockKeyService; the wire never supplies a key here.
            $epoch = new GdkEpoch(epochId: $epochIdRaw, keyHex: sodium_bin2hex($rawKey));
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
