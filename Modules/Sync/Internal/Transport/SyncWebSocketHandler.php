<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
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
 * @internal Plan 04.
 */
final class SyncWebSocketHandler implements WebsocketClientHandler
{
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
        $replayer = new OpLogReplayer(
            db: $this->db,
            deviceKeys: $this->registryService->deviceKeys($this->userId),
        );

        $session = new SyncSession(
            registryService: $this->registryService,
            signer: $this->signer,
            replayer: $replayer,
            framer: $this->framer,
            db: $this->db,
            clock: $this->clock,
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
        try {
            $this->runCatchUp($client, $session);
        } catch (\Throwable $e) {
            $this->logger->warning('SyncWebSocketHandler: catch-up exchange failed.', [
                'error' => $e->getMessage(),
            ]);
            $session->close();
            $client->close();

            return;
        }

        // Step 4: Live streaming loop.
        $deviceKeys = $this->registryService->deviceKeys($this->userId);

        try {
            while ($message = $client->receive()) {
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

        // Read msg1 from the initiator.
        $msg1WsMessage = $client->receive();
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
     */
    private function runCatchUp(WebsocketClient $client, SyncSession $session): void
    {
        $deviceKeys = $this->registryService->deviceKeys($this->userId);

        // 1. Receive peer's CATCH_UP_REQUEST.
        $reqMsg = $client->receive();
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

        // 4. Receive peer's CATCH_UP_RESPONSE + encrypted op frames.
        $peerRespMsg = $client->receive();
        if ($peerRespMsg === null) {
            return;
        }

        $decryptedPeerResp = $session->decrypt($peerRespMsg->buffer());
        $peerResp = $this->catchUp->parseControlMessage($decryptedPeerResp);

        $frameCount = isset($peerResp['frame_count']) && is_int($peerResp['frame_count'])
            ? $peerResp['frame_count']
            : 0;

        for ($i = 0; $i < $frameCount; $i++) {
            $frameMsg = $client->receive();
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

        // Receive and consume peer's CATCH_UP_COMPLETE.
        $completeMsg = $client->receive();
        if ($completeMsg !== null) {
            $session->decrypt($completeMsg->buffer());
        }
    }
}
