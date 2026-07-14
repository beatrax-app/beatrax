<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Sync;

use Amp\CancelledException;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Amp\Websocket\Client\WebsocketConnectException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketMessage;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Psr\Log\LoggerInterface;
use RuntimeException;

use function Amp\Websocket\Client\connect;

/**
 * Outbound-only LAN sync transport for the mobile peer (R3/R4, MOBILE-01).
 *
 * The phone NEVER runs `sync:serve` — iOS/Android forbid a persistent
 * listener (RESEARCH.md Pitfall 1). `syncOnce()` dials the desktop's
 * already-running `sync:serve` listener via `amphp/websocket-client`,
 * completes the SAME Noise IK handshake bytes `SyncWebSocketHandler`
 * expects server-side, drives ONE bounded bilateral `PeerCatchUpExchanger`
 * request/response/complete exchange (importing and reusing its constants
 * and `TransportFramer` framing verbatim — the SPEC forbids any wire
 * change), then closes in a `finally`. This class never persists the
 * connection and contains no listener/server/daemon code.
 *
 * ## iOS Local Network Privacy (15-SPIKE-FINDINGS.md, Spike A — real device GO)
 *
 * The FIRST LAN connection per install is gated by iOS's Local Network
 * Privacy permission. A first-attempt connect denial takes the shape of a
 * clean `Amp\Websocket\Client\WebsocketConnectException`,
 * `Amp\CancelledException` (the connect budget firing), or a
 * `Amp\TimeoutException` mid-handshake — all BEFORE the OS grants access.
 * `syncOnce()` models every one of these as a RETRYABLE outcome (returns
 * `false`), never a thrown fatal (T-15-33) — `MobileSyncTriggerService`
 * re-drives once after the OS prompt has had a chance to resolve.
 *
 * ## Reused as-is (SPEC "consumed as-is" boundary)
 *
 *   - `PeerCatchUpExchanger` — catch-up request/response/complete
 *     constants + query methods.
 *   - `SyncSession` — Noise encrypt/decrypt, the confirmed-device auth
 *     gate, and additive Ed25519 op verification + replay. Reusing this
 *     (rather than hand-rolling a client-side session) guarantees the
 *     mobile peer's receive path is byte-identical to the desktop's.
 *   - `NoiseHandshakeState::initIkInitiator()` — the same Noise IK state
 *     machine `SyncWebSocketHandler::performHandshake()` drives from the
 *     responder side.
 *
 * ## Peer key resolution
 *
 * Noise IK requires the initiator to already know the responder's static
 * key. The desktop's confirmed X25519 key is resolved from
 * `DeviceRegistryService::deviceX25519Keys()` (Sync Public service,
 * confirmed-only, user-scoped — T-13-01b), excluding the local device's
 * own entry. No confirmed peer key found → `syncOnce()` returns `false`
 * (retryable — pairing / first local catch-up of `device_registry` may not
 * have propagated yet).
 *
 * ## Post-catch-up GDK_EPOCH_WRAP receive (Phase 15 import-join, Task 4, G3)
 *
 * `syncOnce()` additionally receives any pending `GDK_EPOCH_WRAP` frames the
 * desktop pushes immediately after catch-up
 * (`SyncWebSocketHandler::deliverGdkEpochWraps()`, additive/backward-
 * compatible — no wire-frame change) and routes each through
 * `Modules\Sync\Public\Services\GdkEpochDeliveryGateway` (the Sync Public
 * seam wrapping `GdkEpochControlHandler::handle()`), which validates,
 * sealed-box-opens, and appends the recovered epoch key to the LOCAL
 * device's keyring under the LOCAL app-lock KEK — never a wire-supplied
 * key. This is the ONLY new wire-adjacent behavior this plan adds; the
 * catch-up/op-exchange protocol itself is unchanged.
 *
 * @internal Plan 05 — dialed by `MobileSyncTriggerService` only. Never a
 *           listener/server/daemon.
 */
final class LanSyncClient
{
    /** Seconds to wait for the initial WebSocket connect (mirrors Spike A's own connect budget). */
    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    /** Seconds to wait for each handshake/catch-up receive round-trip. */
    private const float READ_TIMEOUT_SECONDS = 15.0;

    /**
     * Maximum number of catch-up frames accepted from the desktop's
     * declared frame_count (mirrors SyncWebSocketHandler::MAX_CATCHUP_FRAMES
     * — CR-05 amplification guard, reused verbatim on the client side too).
     */
    private const int MAX_CATCHUP_FRAMES = 100_000;

    /**
     * Maximum GDK_EPOCH_WRAP frames accepted from the post-catch-up push
     * (Phase 15 import-join, Task 4, G3) — mirrors
     * `SyncWebSocketHandler::MAX_GDK_WRAPS_PER_CONNECT`. Bounded so an
     * unbounded backlog cannot pin this fiber; a backlog larger than this
     * is picked up on the NEXT connect (`RelayMailbox::drain()`'s own
     * existing pagination contract, unchanged).
     */
    private const int MAX_GDK_WRAPS_PER_CONNECT = 100;

    /**
     * Seconds to wait for each post-catch-up GDK_EPOCH_WRAP frame — a SHORT,
     * DEDICATED bound (unlike READ_TIMEOUT_SECONDS's generous 15s catch-up
     * budget): the desktop pushes any pending wraps immediately and
     * synchronously after catch-up completes
     * (`SyncWebSocketHandler::runCatchUp()` step 6), so a short idle bound
     * is sufficient to detect "no more wraps" without stalling every single
     * sync (most of which have zero pending wraps) behind the full catch-up
     * timeout.
     */
    private const float GDK_WRAP_READ_TIMEOUT_SECONDS = 2.0;

    public function __construct(
        private readonly DeviceRegistryService $registryService,
        private readonly DeviceKeySigner $signer,
        private readonly TransportFramer $framer,
        private readonly PeerCatchUpExchanger $catchUp,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly MergeRulesRegistry $rules,
        private readonly GdkEpochDeliveryGateway $epochDelivery,
        private readonly ?SearchIndexWriterContract $searchWriter = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    /**
     * Dial the desktop's `sync:serve` listener, run one bounded bidirectional
     * catch-up exchange, receive any pushed GDK epoch wraps, then close.
     *
     * @return bool `true` on a completed catch-up exchange; `false` for any
     *              RETRYABLE outcome (no confirmed peer key yet, or the
     *              connection/handshake did not complete — including an
     *              iOS Local Network Privacy first-attempt denial). The
     *              caller decides whether/when to retry. A genuinely
     *              unexpected failure (the peer's revealed key is not a
     *              confirmed device) still throws — that is a real
     *              security-relevant rejection, not a transient outcome.
     */
    public function syncOnce(string $host, int $port, DeviceIdentityDto $identity, Session $session): bool
    {
        $peerStaticHex = $this->resolvePeerStaticKeyHex($identity);
        if ($peerStaticHex === null) {
            $this->logger?->info('LanSyncClient: no confirmed LAN peer key yet — skipping (retryable).');

            return false;
        }

        $uri = "ws://{$host}:{$port}/";
        $connection = null;

        try {
            $connection = connect($uri, new TimeoutCancellation(self::CONNECT_TIMEOUT_SECONDS));

            $noiseSession = $this->performHandshake($connection, $identity, $peerStaticHex);

            [$syncSession, $deviceKeys] = $this->buildSyncSession($identity);

            $admitted = $syncSession->authenticate($noiseSession, $identity->userId, $identity->deviceId);
            if (! $admitted) {
                throw new RuntimeException(
                    'LanSyncClient: desktop peer failed the confirmed-device auth gate (T-13-13).'
                );
            }

            $this->runCatchUp($connection, $syncSession, $identity->userId, $identity->deviceId, $deviceKeys);

            // Phase 15 import-join (Task 4, G3): consume any GDK_EPOCH_WRAP
            // frames the desktop pushes immediately after catch-up
            // (SyncWebSocketHandler::deliverGdkEpochWraps(), step 6 of ITS
            // runCatchUp()) — inside the STILL-OPEN Noise session (the
            // wraps were encrypted with $syncSession->encrypt(), so
            // decrypting them requires this SAME session, not a new
            // connection). Must run BEFORE session->close() below.
            $this->receiveGdkEpochWraps($connection, $syncSession, $identity->userId, $session);

            $syncSession->close();

            return true;
        } catch (WebsocketConnectException|CancelledException|TimeoutException $e) {
            // Spike A: the FIRST LAN connect per install can hit the iOS
            // Local Network Privacy gate — a clean connect timeout/refused/
            // handshake-stall BEFORE the OS grants access. Modelled as
            // retryable, never a thrown fatal (T-15-33).
            $this->logger?->info('LanSyncClient: LAN dial did not complete (retryable).', [
                'reason' => $e::class,
            ]);

            return false;
        } finally {
            $connection?->close();
        }
    }

    /**
     * Resolve the desktop's confirmed X25519 static key (hex), excluding
     * the local device's own entry. Returns null when no other confirmed
     * device is known yet.
     */
    private function resolvePeerStaticKeyHex(DeviceIdentityDto $identity): ?string
    {
        $confirmed = $this->registryService->deviceX25519Keys($identity->userId);
        unset($confirmed[$identity->deviceId]);

        // Single-peer household pairing (Phase 12) — take the first
        // confirmed non-self device. Multi-peer selection is out of this
        // plan's scope.
        $values = array_values($confirmed);

        return $values[0] ?? null;
    }

    /**
     * Run the Noise IK handshake as the INITIATOR — the same message bytes
     * `SyncWebSocketHandler::performHandshake()` expects on the responder
     * side.
     *
     * @throws RuntimeException on premature disconnect.
     */
    private function performHandshake(
        WebsocketConnection $connection,
        DeviceIdentityDto $identity,
        string $peerStaticHex,
    ): NoiseSession {
        $localSecret = sodium_hex2bin($identity->x25519SecretKeyHex);
        $localPublic = sodium_hex2bin($identity->x25519PublicKeyHex);
        $peerStatic = sodium_hex2bin($peerStaticHex);

        $initHs = NoiseHandshakeState::initIkInitiator($localSecret, $localPublic, $peerStatic);

        $msg1 = $initHs->writeMessage('');
        $connection->sendBinary($msg1);

        $msg2Message = $connection->receive(new TimeoutCancellation(self::READ_TIMEOUT_SECONDS));
        if ($msg2Message === null) {
            throw new RuntimeException('LanSyncClient: peer disconnected before sending Noise msg2.');
        }
        $initHs->readMessage($msg2Message->buffer());

        [$sendCipher, $recvCipher, $peerStaticRevealed] = $initHs->split();

        return new NoiseSession($sendCipher, $recvCipher, $peerStaticRevealed);
    }

    /**
     * Build a fresh SyncSession + the connect-time confirmed Ed25519
     * device-key snapshot (mirrors SyncWebSocketHandler's WR-04 snapshot
     * discipline — same trust set for auth, catch-up, and replay).
     *
     * @return array{0: SyncSession, 1: array<string, string>}
     */
    private function buildSyncSession(DeviceIdentityDto $identity): array
    {
        $deviceKeys = $this->registryService->deviceKeys($identity->userId);

        $replayer = new OpLogReplayer(
            db: $this->db,
            deviceKeys: $deviceKeys,
            rules: $this->rules,
            searchWriter: $this->searchWriter,
        );

        $session = new SyncSession(
            registryService: $this->registryService,
            signer: $this->signer,
            replayer: $replayer,
            framer: $this->framer,
            db: $this->db,
            clock: $this->clock,
            logger: $this->logger,
        );

        return [$session, $deviceKeys];
    }

    /**
     * Run the bilateral CATCH_UP_REQUEST/RESPONSE/COMPLETE exchange as the
     * INITIATOR — the exact mirror image of
     * `SyncWebSocketHandler::runCatchUp()`'s responder-side sequence, so
     * each side's read order matches the other side's write order:
     *
     *   1. SEND our CATCH_UP_REQUEST (we speak first as the initiator).
     *   2. RECEIVE the desktop's CATCH_UP_RESPONSE control + its frames.
     *   3. RECEIVE the desktop's own CATCH_UP_REQUEST.
     *   4. SEND our CATCH_UP_RESPONSE control + our own outgoing frames.
     *   5. RECEIVE the desktop's CATCH_UP_COMPLETE, then SEND ours.
     *
     * @param  array<string, string>  $deviceKeys  Connect-time confirmed
     *                                             Ed25519 key snapshot.
     */
    private function runCatchUp(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        int $userId,
        string $localDeviceId,
        array $deviceKeys,
    ): void {
        // 1. SEND our CATCH_UP_REQUEST.
        $myReq = $this->catchUp->buildRequest($userId, $localDeviceId);
        $connection->sendBinary($syncSession->encrypt(json_encode($myReq, JSON_THROW_ON_ERROR)));

        // 2. RECEIVE the desktop's CATCH_UP_RESPONSE control + frames.
        $respMsg = $this->receiveWithTimeout($connection, 'catch-up response');
        if ($respMsg === null) {
            return;
        }

        $resp = $this->catchUp->parseControlMessage($syncSession->decrypt($respMsg->buffer()));
        $declaredFrameCount = isset($resp['frame_count']) && is_int($resp['frame_count']) ? $resp['frame_count'] : 0;
        // CR-05 mirror: clamp the desktop-declared frame_count so a
        // corrupted/malicious response cannot pin this bounded burst.
        $frameCount = max(0, min($declaredFrameCount, self::MAX_CATCHUP_FRAMES));

        for ($i = 0; $i < $frameCount; $i++) {
            $frameMsg = $this->receiveWithTimeout($connection, 'catch-up frame');
            if ($frameMsg === null) {
                break;
            }

            $syncSession->receiveOps($frameMsg->buffer(), $userId, $deviceKeys);
        }

        // 3. RECEIVE the desktop's own CATCH_UP_REQUEST (its watermark).
        $peerReqMsg = $this->receiveWithTimeout($connection, 'catch-up request');
        if ($peerReqMsg === null) {
            return;
        }

        $peerReq = $this->catchUp->parseControlMessage($syncSession->decrypt($peerReqMsg->buffer()));
        $peerHlcL = isset($peerReq['hlc_l']) && is_int($peerReq['hlc_l']) ? max(0, $peerReq['hlc_l']) : 0;
        $peerHlcC = isset($peerReq['hlc_c']) && is_int($peerReq['hlc_c']) ? max(0, $peerReq['hlc_c']) : 0;

        // 4. SEND our CATCH_UP_RESPONSE control + our own outgoing frames.
        $frames = $this->catchUp->opsAfterWatermark($userId, $peerHlcL, $peerHlcC);
        $myResp = $this->catchUp->buildResponse($frames);
        $connection->sendBinary($syncSession->encrypt(json_encode($myResp, JSON_THROW_ON_ERROR)));

        foreach ($frames as $frame) {
            $connection->sendBinary($syncSession->encrypt($frame));
        }

        // 5. RECEIVE the desktop's CATCH_UP_COMPLETE, then SEND ours.
        $peerCompleteMsg = $this->receiveWithTimeout($connection, 'catch-up complete');
        if ($peerCompleteMsg !== null) {
            $syncSession->decrypt($peerCompleteMsg->buffer());
        }

        $connection->sendBinary($syncSession->encrypt(
            json_encode($this->catchUp->buildComplete(), JSON_THROW_ON_ERROR)
        ));
    }

    /**
     * Post-catch-up GDK_EPOCH_WRAP receive step (Phase 15 import-join,
     * Task 4, G3) — the CLIENT (initiator) read-side mirror of
     * `SyncWebSocketHandler::deliverGdkEpochWraps()`'s outbound push
     * (`SyncWebSocketHandler::runCatchUp()` step 6): the desktop, right
     * after catch-up completes, pushes any `RelayMailbox` rows addressed to
     * THIS device as `$session->encrypt($blob)` frames over the SAME
     * already-authenticated Noise session, then proceeds to its own
     * "live stream" read loop (it does not close the connection or send
     * anything further). This method drains up to
     * `MAX_GDK_WRAPS_PER_CONNECT` such frames, each bounded by a SHORT idle
     * timeout — a non-wrap message, a malformed/unparseable frame, or an
     * idle timeout all end the loop (the desktop sends ONLY wrap frames in
     * this fixed step; a non-wrap read means the push is over).
     *
     * SECURITY (WR-07, threat-model item 3): the wrap is routed into
     * `GdkEpochDeliveryGateway::receiveEpochWrap()` (-> `GdkEpochControlHandler
     * ::handle()`) ONLY from inside THIS still-open, already-authenticated
     * session — `$syncSession->authenticate()` already verified the peer's
     * static key against the confirmed-only device registry before catch-up
     * ever ran (`syncOnce()` above). This satisfies `handle()`'s documented
     * authenticated-channel precondition. Never wire an unauthenticated
     * source (e.g. a raw relay-pushed blob) into this path —
     * `MobileSyncTriggerService::relayLeg()` deliberately stays a
     * non-installing drain for exactly this reason.
     *
     * Never throws on a malformed wrap — `receiveEpochWrap()`/`handle()`
     * already logs and returns; a parse failure here simply ends the loop.
     *
     * @internal Public so this fixed-point receive step is directly
     *           testable without constructing a live amphp WebSocket
     *           connection (mirrors `SyncWebSocketHandler::
     *           deliverGdkEpochWraps()`'s identical public-for-testability
     *           precedent) — a real loopback WebSocket TCP connection is
     *           Manual-Only Verification (`LanDirectSessionTest`'s own
     *           documented precedent).
     */
    public function receiveGdkEpochWraps(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        int $userId,
        Session $session,
    ): void {
        for ($i = 0; $i < self::MAX_GDK_WRAPS_PER_CONNECT; $i++) {
            try {
                $message = $connection->receive(new TimeoutCancellation(self::GDK_WRAP_READ_TIMEOUT_SECONDS));
            } catch (TimeoutException) {
                // No more wraps pending — the desktop's fixed push step is
                // over (it never sends anything further until the phone
                // itself disconnects). Not an error.
                return;
            }

            if ($message === null) {
                // Clean disconnect — nothing more to read.
                return;
            }

            $decrypted = $syncSession->decrypt($message->buffer());

            try {
                $parsed = $this->catchUp->parseControlMessage($decrypted);
            } catch (\UnexpectedValueException) {
                // Malformed frame — the desktop's fixed step only ever
                // sends wrap frames, so treat this as "push is over" rather
                // than throwing.
                return;
            }

            // 'GDK_EPOCH_WRAP' mirrors Modules\Sync\Internal\Crypto\
            // GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP verbatim — that
            // class is off-limits to this module directly (BoundaryRule);
            // the literal wire-protocol string is not a class reference.
            if (($parsed['type'] ?? null) !== 'GDK_EPOCH_WRAP') {
                // A non-wrap message ends this fixed step.
                return;
            }

            $this->epochDelivery->receiveEpochWrap($decrypted, $userId, $session);
        }
    }

    /**
     * Receive a WebSocket message bounded by an idle timeout — mirrors
     * `SyncWebSocketHandler::receiveWithTimeout()`.
     *
     * @throws TimeoutException When the peer sends nothing within the bound.
     */
    private function receiveWithTimeout(WebsocketConnection $connection, string $phase): ?WebsocketMessage
    {
        try {
            return $connection->receive(new TimeoutCancellation(self::READ_TIMEOUT_SECONDS));
        } catch (TimeoutException $e) {
            $this->logger?->info('LanSyncClient: receive timed out.', [
                'phase' => $phase,
            ]);

            throw $e;
        }
    }
}
