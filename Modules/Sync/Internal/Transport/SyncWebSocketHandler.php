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
use Illuminate\Container\Container;
use Illuminate\Contracts\Session\Session as LaravelSession;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Contracts\Clock;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Exceptions\PeerDisconnectedException;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

/**
 * @link ../../../../.docs/features/sync/architecture.md
 */
final class SyncWebSocketHandler implements WebsocketClientHandler
{
    // Caps attacker-paced unbounded receive/op_log growth from a peer's
    // declared frame_count.
    private const MAX_CATCHUP_FRAMES = 100_000;

    // Bounds the pre-auth slow-loris window: a peer that connects but never
    // sends msg1 is dropped instead of parking a fiber forever.
    private const HANDSHAKE_TIMEOUT_SECONDS = 10.0;

    // A peer that stalls mid-stream is dropped rather than pinning the
    // fiber. Generous enough for legitimate slow links + large replay batches.
    private const READ_TIMEOUT_SECONDS = 60.0;

    // Bounded like MAX_CATCHUP_FRAMES so an unbounded mailbox backlog can't
    // pin this fiber; a larger backlog is picked up on the peer's next connect.
    private const MAX_GDK_WRAPS_PER_CONNECT = 100;

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
        private readonly ?MergeRulesRegistry $rules = null,
        private readonly ?SearchIndexWriterContract $searchWriter = null,
    ) {}

    // Called by SyncServeCommand to pass the device_id to
    // MdnsAdvertiser::advertise() without exposing the private field directly.
    public function localDeviceId(): string
    {
        return $this->localDeviceId;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        try {
            $noiseSession = $this->performHandshake($client);
        } catch (\Throwable $e) {
            $this->logger->warning('SyncWebSocketHandler: Noise handshake failed.', [
                'error' => $e->getMessage(),
            ]);
            $client->close();

            return;
        }

        // The confirmed-device key map is read ONCE and reused as a
        // connect-time snapshot for the whole session — revocation only
        // takes effect on the peer's NEXT reconnect. Callers needing
        // immediate revocation must drop the connection.
        $deviceKeys = $this->registryService->deviceKeys($this->userId);

        // Built with the same MergeRulesRegistry and SearchIndexWriterContract
        // the container injects elsewhere, so ops replayed via the live sync
        // path keep the FTS search index fresh and use config-driven merge
        // rules, matching every other replay path.
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

        try {
            // Each live read is bounded by an idle timeout: a peer that
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

    // Wire format: binary WebSocket messages, one per Noise message (msg1
    // initiator -> responder, msg2 responder -> initiator). After split(),
    // peerStaticPublicKey() is set on the returned session.
    /**
     * @throws \RuntimeException On AEAD failure or premature disconnect.
     */
    private function performHandshake(WebsocketClient $client): NoiseSession
    {
        $respHs = NoiseHandshakeState::initIkResponder(
            $this->localStaticSecret,
            $this->localStaticPublic,
        );

        $msg1WsMessage = $this->receiveWithTimeout(
            $client,
            self::HANDSHAKE_TIMEOUT_SECONDS,
            'Noise handshake msg1',
        );
        if ($msg1WsMessage === null) {
            throw PeerDisconnectedException::beforeHandshakeMessage('msg1');
        }
        $respHs->readMessage($msg1WsMessage->buffer());

        $msg2 = $respHs->writeMessage('');
        $client->sendBinary($msg2);

        [$sendCipher, $recvCipher, $peerStatic] = $respHs->split();

        return new NoiseSession($sendCipher, $recvCipher, $peerStatic);
    }

    // Protocol (responder's perspective): request/response/request/response/
    // complete, both sides exchanging HLC watermarks and op frames (see @link).
    /**
     * @param  array<string, string>  $deviceKeys  Connect-time confirmed-device key
     *                                             snapshot, shared with the
     *                                             replayer and the live loop.
     */
    private function runCatchUp(WebsocketClient $client, SyncSession $session, array $deviceKeys): void
    {
        $reqMsg = $this->receiveWithTimeout($client, self::READ_TIMEOUT_SECONDS, 'catch-up request');
        if ($reqMsg === null) {
            return;
        }

        $decryptedReq = $session->decrypt($reqMsg->buffer());
        $req = $this->catchUp->parseControlMessage($decryptedReq);

        // Clamp watermark fields to non-negative — a negative hlc_l/hlc_c
        // would make opsAfterWatermark()'s `> $peerHlcL` predicate match the
        // entire op_log, a full-history dump on every reconnect.
        $peerHlcL = isset($req['hlc_l']) && is_int($req['hlc_l']) ? max(0, $req['hlc_l']) : 0;
        $peerHlcC = isset($req['hlc_c']) && is_int($req['hlc_c']) ? max(0, $req['hlc_c']) : 0;

        $frames = $this->catchUp->opsAfterWatermark($this->userId, $peerHlcL, $peerHlcC);
        $respControl = $this->catchUp->buildResponse($frames);
        $client->sendBinary($session->encrypt(
            json_encode($respControl, JSON_THROW_ON_ERROR)
        ));

        foreach ($frames as $frame) {
            $client->sendBinary($session->encrypt($frame));
        }

        $myReq = $this->catchUp->buildRequest($this->userId, $this->localDeviceId);
        $client->sendBinary($session->encrypt(
            json_encode($myReq, JSON_THROW_ON_ERROR)
        ));

        $peerRespMsg = $this->receiveWithTimeout($client, self::READ_TIMEOUT_SECONDS, 'catch-up response');
        if ($peerRespMsg === null) {
            return;
        }

        $decryptedPeerResp = $session->decrypt($peerRespMsg->buffer());
        $peerResp = $this->catchUp->parseControlMessage($decryptedPeerResp);

        $declaredFrameCount = isset($peerResp['frame_count']) && is_int($peerResp['frame_count'])
            ? $peerResp['frame_count']
            : 0;

        // Clamp the attacker-declared frame_count: a negative value yields 0
        // (loop never runs); a huge value is capped so a malicious peer
        // cannot stream frames unboundedly and grow the op_log without limit.
        $frameCount = max(0, min($declaredFrameCount, self::MAX_CATCHUP_FRAMES));

        for ($i = 0; $i < $frameCount; $i++) {
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

        $complete = $this->catchUp->buildComplete();
        $client->sendBinary($session->encrypt(
            json_encode($complete, JSON_THROW_ON_ERROR)
        ));

        $completeMsg = $this->receiveWithTimeout($client, self::READ_TIMEOUT_SECONDS, 'catch-up complete');
        if ($completeMsg !== null) {
            $session->decrypt($completeMsg->buffer());
        }

        // Fixed-point GDK_EPOCH_WRAP delivery: a dedicated linear step
        // appended immediately after catch-up completes, mirroring the
        // existing request/response/complete sequencing style rather than a
        // generic control-message dispatch switch.
        $this->deliverGdkEpochWraps($client, $session);
    }

    // Delivers pending sealed-box GDK epoch wraps over the already-
    // authenticated live Noise session (outbound to the peer, inbound via
    // GdkEpochControlHandler) as an optimization over the relay round-trip
    // (see @link). Degrades gracefully when a dependency is unavailable.
    /**
     * @internal Public so the fixed-point delivery step is directly
     *           testable without constructing a live amphp WebSocket connection.
     */
    public function deliverGdkEpochWraps(WebsocketClient $client, SyncSession $session): void
    {
        $container = Container::getInstance();
        if (! $container->bound(RelayMailbox::class)) {
            return;
        }

        /** @var RelayMailbox $relayMailbox */
        $relayMailbox = $container->make(RelayMailbox::class);

        $peerDeviceId = $session->peerDeviceId();
        if ($peerDeviceId !== null && $peerDeviceId !== '') {
            foreach ($relayMailbox->drain($peerDeviceId, self::MAX_GDK_WRAPS_PER_CONNECT) as $row) {
                $blob = is_string($row->blob) ? $row->blob : '';
                $rowId = is_numeric($row->id) ? (int) $row->id : null;
                if ($blob === '' || $rowId === null) {
                    continue;
                }

                $client->sendBinary($session->encrypt($blob));
                $relayMailbox->confirm($rowId);
            }
        }

        if ($this->localDeviceId === ''
            || ! $container->bound(GdkEpochControlHandler::class)
            || ! $container->bound(LaravelSession::class)
        ) {
            return;
        }

        /** @var GdkEpochControlHandler $handler */
        $handler = $container->make(GdkEpochControlHandler::class);
        $laravelSession = $container->make(LaravelSession::class);

        foreach ($relayMailbox->drain($this->localDeviceId, self::MAX_GDK_WRAPS_PER_CONNECT) as $row) {
            $blob = is_string($row->blob) ? $row->blob : '';
            $rowId = is_numeric($row->id) ? (int) $row->id : null;
            if ($blob === '' || $rowId === null) {
                continue;
            }

            $handler->handle($blob, $this->userId, $laravelSession);
            $relayMailbox->confirm($rowId);
        }
    }

    // A peer that stalls (pre-auth slow-loris or mid-stream) cannot pin this
    // fiber indefinitely; on timeout the connection is closed and a
    // TimeoutException is thrown so the caller's try/catch tears down the session.
    /**
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
