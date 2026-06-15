<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketMessage;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

/**
 * amphp WebSocket handler driving the full sync session lifecycle over the wire.
 *
 * Sequence for each incoming connection (RESEARCH amphp WebSocket Handler Skeleton):
 *
 *   1. Noise IK handshake using first N binary frames.
 *      - We are the responder; initiator sends msg1, we send msg2.
 *      - No Electron-only logic (D-03): handler runs identically from sync:serve
 *        artisan daemon or a NativePHP ChildProcess.
 *   2. SyncSession auth gate.
 *      - SyncSession::authenticate() verifies the peer's revealed X25519 static key
 *        against DeviceRegistryService::deviceX25519Keys() (confirmed-only, T-13-01b).
 *      - Non-confirmed peer → close connection, no data exchanged.
 *   3. PeerCatchUpExchanger: bilateral CATCH_UP_REQUEST/RESPONSE/COMPLETE exchange.
 *      - Both sides send their HLC watermark; each responds with missing ops.
 *      - Frames received during catch-up go through SyncSession::receiveOps().
 *   4. Live streaming loop.
 *      - foreach ($client as $message) → SyncSession::receiveOps()
 *
 * ## Host-agnostic design (D-03)
 *
 *   No import of `Native\Desktop\Facades\ChildProcess` or similar Electron-only APIs.
 *   Invoked identically from the sync:serve artisan daemon and NativePHP ChildProcess.
 *
 * ## Static keypair injection
 *
 *   The local device's X25519 static keypair (secret + public) must be provided
 *   at construction time. In production, these come from DeviceIdentityService (Plan 05).
 *   In tests, throwaway keypairs are injected directly.
 *
 * ## Event-loop blocking (Pitfall 3, T-13-12)
 *
 *   SyncSession::receiveOps() calls OpLogReplayer::replay() synchronously. The
 *   amphp caller can wrap receive loops in Amp\async() to keep the loop responsive.
 *   This class stays synchronous for testability.
 *
 * ## DoS hardening (CR-05 / CR-06)
 *
 *   Every receive() — pre-auth handshake, catch-up, and the live loop — is bounded
 *   by a TimeoutCancellation so a stalled or slow-loris peer cannot pin a fiber
 *   indefinitely (CR-06). The catch-up frame loop is additionally bounded by
 *   MAX_CATCHUP_FRAMES so a confirmed-but-malicious peer cannot declare an
 *   unbounded frame_count and stream frames forever (CR-05). A peer that exceeds
 *   either bound has its connection closed.
 *
 * @internal Plan 04.
 */
final class SyncWebSocketHandler implements WebsocketClientHandler
{
    /**
     * Maximum number of catch-up frames accepted from a peer's declared
     * frame_count (CR-05). Caps attacker-paced unbounded receive / op_log growth.
     */
    private const MAX_CATCHUP_FRAMES = 100_000;

    /**
     * Seconds to wait for the Noise handshake msg1 before closing the connection
     * (CR-06). Bounds the pre-auth slow-loris window: a peer that connects but
     * never sends msg1 is dropped instead of parking a fiber forever.
     */
    private const HANDSHAKE_TIMEOUT_SECONDS = 10.0;

    /**
     * Per-receive idle timeout (seconds) for catch-up and live-stream reads
     * (CR-06). A peer that stalls mid-stream is dropped rather than pinning the
     * fiber. Generous enough for legitimate slow links + large replay batches.
     */
    private const READ_TIMEOUT_SECONDS = 60.0;

    /**
     * @param  string  $localStaticSecret  32-byte X25519 secret key for the local device.
     * @param  string  $localStaticPublic  32-byte X25519 public key for the local device.
     * @param  string  $localDeviceId  Device id of this peer (for SyncSession auth context).
     * @param  int  $userId  Authenticated user id (for DB query scoping).
     */
    public function __construct(
        private readonly DeviceRegistryService $registryService,
        private readonly DeviceKeySigner $signer,
        private readonly TransportFramer $framer,
        private readonly PeerCatchUpExchanger $catchUp,
        private readonly DatabaseManager $db,
        private readonly Clock $clock,
        private readonly LoggerInterface $logger,
        private readonly string $localStaticSecret,
        private readonly string $localStaticPublic,
        private readonly string $localDeviceId,
        private readonly int $userId,
    ) {}

    /**
     * Return the local device_id used for mDNS TXT record advertisement.
     *
     * Called by SyncServeCommand to pass the device_id to MdnsAdvertiser::advertise()
     * without exposing the handler's private field directly.
     */
    public function localDeviceId(): string
    {
        return $this->localDeviceId;
    }

    /**
     * Handle a new WebSocket client connection.
     */
    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        // Step 1: Noise IK handshake (responder side).
        try {
            $noiseSession = $this->performHandshake($client);
        } catch (\Throwable $e) {
            $this->logger->warning('SyncWebSocketHandler: Noise handshake failed.', [
                'error' => $e->getMessage(),
            ]);
            $client->close();

            return;
        }

        // Step 2: Build a SyncSession and run the auth gate.
        //
        // WR-04: the confirmed-device key map is read ONCE here and reused as a
        // connect-time SNAPSHOT for the whole session (replayer, catch-up, live
        // loop). Revocation/un-pairing therefore only takes effect on the peer's
        // NEXT reconnect — the live loop is bounded by READ_TIMEOUT_SECONDS so a
        // stale trust set cannot outlive a long idle gap. This is an explicit,
        // documented trade-off: re-reading the map per frame would add a DB query
        // to the hot path. Callers needing immediate revocation must drop the
        // connection (which forces a re-read on reconnect).
        $deviceKeys = $this->registryService->deviceKeys($this->userId);

        $replayer = new OpLogReplayer(
            db: $this->db,
            deviceKeys: $deviceKeys,
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

        if (! $session->authenticate($noiseSession, $this->userId, $this->localDeviceId)) {
            $this->logger->warning('SyncWebSocketHandler: peer auth failed — closing connection.', [
                'user_id' => $this->userId,
            ]);
            $client->close();

            return;
        }

        $this->logger->info('SyncWebSocketHandler: peer authenticated.', [
            'peer_device_id' => $session->peerDeviceId(),
            'user_id' => $this->userId,
        ]);

        // Step 3: Bilateral catch-up exchange (RESEARCH Pattern 6).
        // Reuses the connect-time $deviceKeys snapshot (WR-04).
        try {
            $this->runCatchUp($client, $session, $deviceKeys);
        } catch (\Throwable $e) {
            $this->logger->warning('SyncWebSocketHandler: catch-up exchange failed.', [
                'error' => $e->getMessage(),
            ]);
            $session->close();
            $client->close();

            return;
        }

        // Step 4: Live streaming loop. Reuses the connect-time $deviceKeys
        // snapshot (WR-04) — same trust set as catch-up and the replayer.
        try {
            // Each live read is bounded by an idle timeout (CR-06): a peer that
            // authenticates then stalls is dropped rather than pinning this fiber.
            while ($message = $this->receiveWithTimeout(
                $client,
                self::READ_TIMEOUT_SECONDS,
                'live stream',
            )) {
                $ciphertext = $message->buffer();
                $session->receiveOps($ciphertext, $this->userId, $deviceKeys);
            }
        } catch (\Throwable $e) {
            $this->logger->warning('SyncWebSocketHandler: live stream error.', [
                'error' => $e->getMessage(),
            ]);
        }

        $session->close();
    }

    /**
     * Perform the Noise IK handshake as the responder.
     *
     * Wire format: binary WebSocket messages (one per Noise message).
     *   - msg1: initiator → responder (read from $client->receive())
     *   - msg2: responder → initiator (write via $client->sendBinary())
     *
     * After split(), peerStaticPublicKey() is set on the returned session.
     *
     * @throws \RuntimeException On AEAD failure or premature disconnect.
     */
    private function performHandshake(WebsocketClient $client): NoiseSession
    {
        $respHs = NoiseHandshakeState::initIkResponder(
            $this->localStaticSecret,
            $this->localStaticPublic,
        );

        // Read msg1 from the initiator, bounded by a handshake timeout (CR-06).
        // A peer that connects and never sends msg1 is dropped instead of pinning
        // this fiber forever (slow-loris pre-auth).
        $msg1WsMessage = $this->receiveWithTimeout(
            $client,
            self::HANDSHAKE_TIMEOUT_SECONDS,
            'Noise handshake msg1',
        );
        if ($msg1WsMessage === null) {
            throw new \RuntimeException(
                'SyncWebSocketHandler: peer disconnected before sending Noise msg1.'
            );
        }
        $respHs->readMessage($msg1WsMessage->buffer());

        // Write msg2.
        $msg2 = $respHs->writeMessage('');
        $client->sendBinary($msg2);

        // Split into send/recv ciphers + peer static key.
        [$sendCipher, $recvCipher, $peerStatic] = $respHs->split();

        return new NoiseSession($sendCipher, $recvCipher, $peerStatic);
    }

    /**
     * Run the bilateral CATCH_UP_REQUEST/RESPONSE/COMPLETE exchange (RESEARCH Pattern 6).
     *
     * Control messages (CATCH_UP_REQUEST, CATCH_UP_RESPONSE, CATCH_UP_COMPLETE) are
     * Noise-encrypted JSON strings sent as binary WebSocket messages.
     * Data frames (op batches) are Noise-encrypted TransportFramer frames.
     *
     * Protocol (from the responder's perspective):
     *   1. Receive initiator's CATCH_UP_REQUEST (encrypted JSON).
     *   2. Send our CATCH_UP_RESPONSE control msg (encrypted JSON) + encrypted op frames.
     *   3. Send our CATCH_UP_REQUEST (encrypted JSON).
     *   4. Receive initiator's CATCH_UP_RESPONSE (encrypted JSON) + encrypted op frames.
     *   5. Exchange CATCH_UP_COMPLETE (encrypted JSON).
     *
     * @param  array<string, string>  $deviceKeys  Connect-time confirmed-device key
     *                                             snapshot (WR-04), shared with the
     *                                             replayer and the live loop.
     */
    private function runCatchUp(WebsocketClient $client, SyncSession $session, array $deviceKeys): void
    {
        // 1. Receive peer's CATCH_UP_REQUEST (timeout-bounded — CR-06).
        $reqMsg = $this->receiveWithTimeout($client, self::READ_TIMEOUT_SECONDS, 'catch-up request');
        if ($reqMsg === null) {
            return;
        }

        $decryptedReq = $session->decrypt($reqMsg->buffer());
        $req = $this->catchUp->parseControlMessage($decryptedReq);

        $peerHlcL = isset($req['hlc_l']) && is_int($req['hlc_l']) ? $req['hlc_l'] : 0;
        $peerHlcC = isset($req['hlc_c']) && is_int($req['hlc_c']) ? $req['hlc_c'] : 0;

        // 2. Send our CATCH_UP_RESPONSE: control msg + op frames.
        $frames = $this->catchUp->opsAfterWatermark($this->userId, $peerHlcL, $peerHlcC);
        $respControl = $this->catchUp->buildResponse($frames);
        $client->sendBinary($session->encrypt(
            json_encode($respControl, JSON_THROW_ON_ERROR)
        ));

        foreach ($frames as $frame) {
            $client->sendBinary($session->encrypt($frame));
        }

        // 3. Send our CATCH_UP_REQUEST.
        $myReq = $this->catchUp->buildRequest($this->userId, $this->localDeviceId);
        $client->sendBinary($session->encrypt(
            json_encode($myReq, JSON_THROW_ON_ERROR)
        ));

        // 4. Receive peer's CATCH_UP_RESPONSE + encrypted op frames (timeout-bounded — CR-06).
        $peerRespMsg = $this->receiveWithTimeout($client, self::READ_TIMEOUT_SECONDS, 'catch-up response');
        if ($peerRespMsg === null) {
            return;
        }

        $decryptedPeerResp = $session->decrypt($peerRespMsg->buffer());
        $peerResp = $this->catchUp->parseControlMessage($decryptedPeerResp);

        $declaredFrameCount = isset($peerResp['frame_count']) && is_int($peerResp['frame_count'])
            ? $peerResp['frame_count']
            : 0;

        // CR-05: clamp the attacker-declared frame_count. A negative value (WR-06)
        // yields 0 (loop never runs); a huge value is capped so a malicious peer
        // cannot stream frames unboundedly and grow the op_log without limit.
        $frameCount = max(0, min($declaredFrameCount, self::MAX_CATCHUP_FRAMES));

        for ($i = 0; $i < $frameCount; $i++) {
            // CR-06: each frame read is bounded by an idle timeout so a peer that
            // declares N frames but stalls mid-stream cannot pin the fiber.
            $frameMsg = $this->receiveWithTimeout(
                $client,
                self::READ_TIMEOUT_SECONDS,
                'catch-up frame',
            );
            if ($frameMsg === null) {
                break;
            }

            $session->receiveOps($frameMsg->buffer(), $this->userId, $deviceKeys);
        }

        // 5. Exchange CATCH_UP_COMPLETE.
        $complete = $this->catchUp->buildComplete();
        $client->sendBinary($session->encrypt(
            json_encode($complete, JSON_THROW_ON_ERROR)
        ));

        // Receive and consume peer's CATCH_UP_COMPLETE (timeout-bounded — CR-06).
        $completeMsg = $this->receiveWithTimeout($client, self::READ_TIMEOUT_SECONDS, 'catch-up complete');
        if ($completeMsg !== null) {
            $session->decrypt($completeMsg->buffer());
        }
    }

    /**
     * Receive a WebSocket message bounded by an idle timeout (CR-06).
     *
     * Wraps WebsocketClient::receive() with an Amp\TimeoutCancellation so a peer
     * that stalls (pre-auth slow-loris or mid-stream) cannot pin this fiber
     * indefinitely. On timeout the connection is closed and a TimeoutException is
     * thrown so the caller's existing try/catch logs and tears down the session.
     *
     * @param  float  $timeoutSeconds  Idle timeout for this single receive.
     * @param  string  $phase  Human-readable phase label for the timeout error.
     *
     * @throws TimeoutException When the peer sends nothing within $timeoutSeconds.
     */
    private function receiveWithTimeout(
        WebsocketClient $client,
        float $timeoutSeconds,
        string $phase,
    ): ?WebsocketMessage {
        try {
            return $client->receive(new TimeoutCancellation($timeoutSeconds));
        } catch (TimeoutException $e) {
            $this->logger->warning('SyncWebSocketHandler: receive timed out — closing connection.', [
                'phase' => $phase,
                'timeout_seconds' => $timeoutSeconds,
            ]);
            $client->close();

            throw $e;
        }
    }
}
