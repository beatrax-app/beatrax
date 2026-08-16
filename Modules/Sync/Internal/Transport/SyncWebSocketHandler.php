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
use Throwable;

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

    // Announces the epoch-push phase and how many wrap frames follow. The
    // phase runs BEFORE catch-up, so it cannot end on a read timeout the way
    // a trailing phase could — that would swallow the catch-up request queued
    // behind it. An explicit count makes the boundary exact, not inferred.
    public const string MSG_GDK_EPOCH_PUSH = 'GDK_EPOCH_PUSH';

    // Told to a peer this device no longer confirms, so a removed device can
    // stop presenting itself as synced. Sent over the completed Noise
    // session, which is what makes it trustworthy: IK proves to the dialling
    // peer that this responder holds the static key it dialled.
    public const string MSG_PEER_REVOKED = 'PEER_REVOKED';

    // How often an open session re-checks that its peer is still trusted.
    // Short enough that a removal takes hold while the user is still looking
    // at the screen, long enough not to query per message.
    private const float REVOCATION_CHECK_SECONDS = 5.0;

    private float $lastRevocationCheck = 0.0;

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
        } catch (Throwable $e) {
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

            // Say WHY before hanging up. Closing silently is indistinguishable
            // from a flaky network, so a device removed here kept describing
            // itself as connected and synced indefinitely.
            $this->tellPeerItIsRevoked($client, $noiseSession);

            $client->close();

            return;
        }

        $this->logger->info('SyncWebSocketHandler: peer authenticated.', [
            'peer_device_id' => $session->peerDeviceId(),
            'user_id' => $this->userId,
        ]);

        try {
            // Keys BEFORE the data they decrypt. Delivered after catch-up, a
            // joining device applied the peer's whole encrypted history against
            // an empty keyring, and every sensitive op landed in quarantine —
            // an audit table with no replay path.
            $this->deliverGdkEpochWraps($client, $session);

            $this->runCatchUp($client, $session, $deviceKeys);
        } catch (Throwable $e) {
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
                // Trust is re-checked on the LIVE connection, not just at
                // connect. Removing a device cleared its row while its open
                // session carried on syncing, so "removed" only took hold
                // whenever the peer next happened to reconnect.
                if ($this->peerWasRevoked($session)) {
                    $this->logger->info('SyncWebSocketHandler: peer revoked mid-session — closing.', [
                        'peer_device_id' => $session->peerDeviceId(),
                        'user_id' => $this->userId,
                    ]);

                    break;
                }

                $ciphertext = $message->buffer();
                $session->receiveOps($ciphertext, $this->userId, $deviceKeys);
            }
        } catch (Throwable $e) {
            $this->logger->warning('SyncWebSocketHandler: live stream error.', [
                'error' => $e->getMessage(),
            ]);
        }

        $session->close();
    }

    // Whether the connected peer has lost its confirmation since this
    // session opened. Throttled: the answer only changes when the user
    // removes a device, so re-asking per message would buy nothing and cost
    // a query on every op.
    private function peerWasRevoked(SyncSession $session): bool
    {
        $now = $this->clock->now()->getTimestamp();

        if ($now - $this->lastRevocationCheck < self::REVOCATION_CHECK_SECONDS) {
            return false;
        }

        $this->lastRevocationCheck = $now;

        $peerDeviceId = $session->peerDeviceId();

        return $peerDeviceId !== null
            && ! $this->registryService->isStillConfirmed($this->userId, $peerDeviceId);
    }

    // Best-effort notice to an unconfirmed peer. Sent on the raw Noise
    // session because SyncSession is deliberately unauthenticated here, and
    // never allowed to throw — the connection is being closed either way.
    private function tellPeerItIsRevoked(WebsocketClient $client, NoiseSession $noiseSession): void
    {
        try {
            $client->sendBinary($noiseSession->encrypt(json_encode(
                ['type' => self::MSG_PEER_REVOKED],
                JSON_THROW_ON_ERROR,
            )));
        } catch (Throwable $e) {
            $this->logger->info('SyncWebSocketHandler: could not tell the peer it was revoked.', [
                'error' => $e->getMessage(),
            ]);
        }
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
    }

    // Sends the epoch-push phase to the connected peer over the already-
    // authenticated live Noise session, as an optimization over the relay
    // round-trip (see @link). Degrades gracefully when the mailbox is unbound.
    /**
     * @internal Public so the fixed-point delivery step is directly
     *           testable without constructing a live amphp WebSocket connection.
     */
    public function deliverGdkEpochWraps(WebsocketClient $client, SyncSession $session): void
    {
        $container = Container::getInstance();
        $relayMailbox = $container->bound(RelayMailbox::class)
            ? $container->make(RelayMailbox::class)
            : null;

        // The header is unconditional — the peer reads it before catch-up and
        // would otherwise block waiting for a phase this side silently
        // skipped. An unbound mailbox is simply a zero-wrap push.
        $wraps = $relayMailbox instanceof RelayMailbox
            ? $this->pendingWrapsForPeer($session, $relayMailbox)
            : [];

        $client->sendBinary($session->encrypt(json_encode([
            'type' => self::MSG_GDK_EPOCH_PUSH,
            'count' => count($wraps),
        ], JSON_THROW_ON_ERROR)));

        if ($relayMailbox instanceof RelayMailbox) {
            $this->sendWrapsToPeer($client, $session, $relayMailbox, $wraps);
        }

        $this->drainInboundEpochWraps();
    }

    // Applies wraps addressed to THIS device from its own mailbox. Nothing
    // here touches the wire, so it stays callable independently of a live
    // peer session — a backlog left by the relay is consumed either way.
    /**
     * @internal Public for the same reason as deliverGdkEpochWraps().
     */
    public function drainInboundEpochWraps(): void
    {
        $container = Container::getInstance();
        if (! $container->bound(RelayMailbox::class)) {
            return;
        }

        /** @var RelayMailbox $relayMailbox */
        $relayMailbox = $container->make(RelayMailbox::class);

        $this->drainInboundWrapsForLocalDevice($container, $relayMailbox);
    }

    // Collects the epoch wraps queued for the connected peer. Separate from
    // sending because the count has to be known before the first frame goes
    // out — the header announcing the phase carries it.
    /**
     * @return list<array{id: int, blob: string}>
     */
    private function pendingWrapsForPeer(SyncSession $session, RelayMailbox $relayMailbox): array
    {
        $peerDeviceId = $session->peerDeviceId();
        if ($peerDeviceId === null || $peerDeviceId === '') {
            return [];
        }

        $wraps = [];

        foreach ($relayMailbox->drain($peerDeviceId, self::MAX_GDK_WRAPS_PER_CONNECT) as $row) {
            $blob = is_string($row->blob) ? $row->blob : '';
            $rowId = is_numeric($row->id) ? (int) $row->id : null;
            if ($blob === '' || $rowId === null) {
                continue;
            }

            // Two protocols share this mailbox: the epoch wraps this step
            // owns, and the pairing frames the relay stores for the same peer.
            // Forwarding both confirmed a leftover PAIR_CONFIRM away down a
            // channel whose reader discards it — wraps behind it included.
            if (! self::isEpochWrap($blob)) {
                continue;
            }

            $wraps[] = ['id' => $rowId, 'blob' => $blob];
        }

        return $wraps;
    }

    // Forwards the collected wraps over the live Noise session, confirming
    // each mailbox row only once it has been handed to the transport so an
    // interrupted drain is retried on the next connect.
    /**
     * @param  list<array{id: int, blob: string}>  $wraps
     */
    private function sendWrapsToPeer(
        WebsocketClient $client,
        SyncSession $session,
        RelayMailbox $relayMailbox,
        array $wraps,
    ): void {
        foreach ($wraps as $wrap) {
            $client->sendBinary($session->encrypt($wrap['blob']));
            $relayMailbox->confirm($wrap['id']);
        }
    }

    // Reads only the envelope type of a blob this device enqueued itself. Not
    // a ZK violation: the relay's own store-and-forward path never calls this,
    // and an epoch wrap is end-to-end material this device is a party to.
    private static function isEpochWrap(string $blob): bool
    {
        /** @var mixed $decoded */
        $decoded = json_decode($blob, true);

        return is_array($decoded)
            && isset($decoded['type'])
            && $decoded['type'] === GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP;
    }

    // Applies sealed-box GDK epoch wraps addressed to THIS device via the
    // control handler, mirroring the outbound drain so a locally-addressed
    // backlog is consumed on the same connect instead of the relay round-trip.
    private function drainInboundWrapsForLocalDevice(Container $container, RelayMailbox $relayMailbox): void
    {
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

            try {
                $handler->handle($blob, $this->userId, $laravelSession);
            } catch (Throwable $e) {
                // The daemon has no unlocked session, so unwrapping can fail
                // outright. Left unconfirmed for a request-scoped drain to
                // retry, and never rethrown: letting it escape aborted the
                // whole exchange after catch-up had already succeeded.
                $this->logger->info('SyncWebSocketHandler: inbound epoch wrap deferred.', [
                    'reason' => $e::class,
                ]);

                continue;
            }

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
