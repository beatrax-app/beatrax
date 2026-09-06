<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Transport;

use Amp\CancelledException;
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
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Search\Public\Contracts\SearchIndexWriterContract;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\InboundGdkWrapDrain;
use Modules\Sync\Internal\Exceptions\PeerDisconnectedException;
use Modules\Sync\Internal\Exceptions\PeerRevokedException;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\Relay\RelayMailbox;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Public\Transport\ProtocolTimings;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
final class SyncWebSocketHandler implements WebsocketClientHandler
{
    // Announces the epoch-push phase and how many wrap frames follow. The
    // phase runs BEFORE catch-up, so it cannot end on a read timeout the way
    // a trailing phase could — that would swallow the catch-up request queued
    // behind it. An explicit count makes the boundary exact, not inferred.
    public const string MSG_GDK_EPOCH_PUSH = GdkEpochDeliveryGateway::MSG_EPOCH_PUSH;

    // How many of the announced wraps the receiver durably accounted for. The
    // sender retires exactly that many, so a drop mid-push costs a retry
    // rather than the only copy of a key nothing re-sends.
    public const string MSG_GDK_EPOCH_ACK = GdkEpochDeliveryGateway::MSG_EPOCH_ACK;

    // Told to a peer this device no longer confirms, so a removed device can
    // stop presenting itself as synced. Sent over the completed Noise
    // session, which is what makes it trustworthy: IK proves to the dialling
    // peer that this responder holds the static key it dialled.
    public const string MSG_PEER_REVOKED = GdkEpochDeliveryGateway::MSG_PEER_REVOKED;

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

    // Scopes the pairing-offer lookup SyncServeCommand mounts alongside this
    // handler. Zero when the daemon spawned without a resolvable identity,
    // which the offer service reads as "no user" and refuses.
    public function localUserId(): int
    {
        return $this->userId;
    }

    public function handleClient(WebsocketClient $client, Request $request, Response $response): void
    {
        try {
            $noiseSession = $this->performHandshake($client);
        } catch (Throwable $e) {
            $this->logger->warning('SyncWebSocketHandler: Noise handshake failed.', [
                ...SafeExceptionContext::describe($e),
            ]);
            $client->close();

            return;
        }

        // Read ONCE as a connect-time snapshot, so revocation takes effect on
        // the peer's NEXT reconnect. NOT the map authenticate() admits this
        // peer on — that one is deviceX25519Keys(), which a confirmed
        // introduction is deliberately absent from and can never widen.
        $deviceKeys = $this->registryService->signatureVerificationKeys($this->userId);

        // Built with the same MergeRulesRegistry and SearchIndexWriterContract
        // the container injects elsewhere, so ops replayed via the live sync
        // path keep the FTS search index fresh and use config-driven merge
        // rules, matching every other replay path.
        $replayer = new OpLogReplayer(
            db: $this->db,
            deviceKeys: $deviceKeys,
            deviceKeysUserId: $this->userId,
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

            // Say WHY before hanging up, where there is a why to say. Closing
            // silently is indistinguishable from a flaky network, so a device
            // removed here kept describing itself as connected and synced.
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
                ...SafeExceptionContext::describe($e),
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
                ProtocolTimings::responderReadSeconds(),
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
                ...SafeExceptionContext::describe($e),
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

    // Best-effort notice to a peer this registry has REVOKED. Sent on the raw
    // Noise session because SyncSession is deliberately unauthenticated here,
    // and never allowed to throw — the connection is being closed either way.
    /**
     * @internal Public so the refusal is testable without a live amphp dial.
     */
    public function tellPeerItIsRevoked(WebsocketClient $client, NoiseSession $noiseSession): void
    {
        // The gate refuses a device this household never admitted on exactly
        // the same branch as one it removed, and the peer's reading of the
        // notice is terminal. Told it while a confirm was still in flight, a
        // phone dropped the desktop for good over a half-finished ceremony.
        if (! $this->registryService->holdsRevokedDeviceWithKeyAgreementKey(
            $this->userId,
            sodium_bin2hex($noiseSession->peerStaticPublicKey()),
        )) {
            $this->logger->info('SyncWebSocketHandler: refused a device this registry never admitted — claiming no revocation.', [
                'user_id' => $this->userId,
            ]);

            return;
        }

        try {
            $client->sendBinary($noiseSession->encrypt(json_encode(
                ['type' => self::MSG_PEER_REVOKED],
                JSON_THROW_ON_ERROR,
            )));
        } catch (Throwable $e) {
            $this->logger->info('SyncWebSocketHandler: could not tell the peer it was revoked.', [
                ...SafeExceptionContext::describe($e),
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
            ProtocolTimings::HANDSHAKE_SECONDS,
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
        $reqMsg = $this->receiveWithTimeout($client, ProtocolTimings::responderReadSeconds(), 'catch-up request');
        if ($reqMsg === null) {
            return;
        }

        $decryptedReq = $session->decrypt($reqMsg->buffer());
        $req = $this->catchUp->parseControlMessage($decryptedReq);

        [$delta, $respControl] = $this->catchUp->answer($this->userId, $req, $session->peerDeviceId() ?? '');
        $client->sendBinary($session->encrypt(
            json_encode($respControl, JSON_THROW_ON_ERROR)
        ));

        // Iterated, not collected: each frame is built as it is sent, so the
        // owed history crosses the wire without ever being resident at once.
        foreach ($delta as $frame) {
            $client->sendBinary($session->encrypt($frame));
        }

        $myReq = $this->catchUp->buildRequest($this->userId, $this->localDeviceId, $session->peerDeviceId() ?? '');
        $client->sendBinary($session->encrypt(
            json_encode($myReq, JSON_THROW_ON_ERROR)
        ));

        $peerRespMsg = $this->receiveWithTimeout($client, ProtocolTimings::responderReadSeconds(), 'catch-up response');
        if ($peerRespMsg === null) {
            return;
        }

        $decryptedPeerResp = $session->decrypt($peerRespMsg->buffer());
        $peerResp = $this->catchUp->parseControlMessage($decryptedPeerResp);

        // Before the frames, not after: the frames can end early on a timeout,
        // and the one thing that says WHY this exchange came back thin is the
        // list the peer sent with the count.
        $this->catchUp->recordIntroductions($this->userId, $peerResp, $session->peerDeviceId() ?? '');

        $declaredFrameCount = isset($peerResp['frame_count']) && is_int($peerResp['frame_count'])
            ? $peerResp['frame_count']
            : 0;

        // Clamp the attacker-declared frame_count: a negative value yields 0
        // (loop never runs); a huge value is capped so a malicious peer
        // cannot stream frames unboundedly and grow the op_log without limit.
        $frameCount = max(0, min($declaredFrameCount, GdkEpochDeliveryGateway::MAX_CATCHUP_FRAMES));

        for ($i = 0; $i < $frameCount; $i++) {
            $frameMsg = $this->receiveWithTimeout(
                $client,
                ProtocolTimings::responderReadSeconds(),
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

        $completeMsg = $this->receiveWithTimeout($client, ProtocolTimings::responderReadSeconds(), 'catch-up complete');
        if ($completeMsg !== null) {
            $session->decrypt($completeMsg->buffer());
        }
    }

    // The epoch-push phase, over the already-authenticated live Noise session.
    // Both legs run: this device pushes what it holds for the peer, then reads
    // what the peer holds for it, because a one-directional exchange leaves the
    // receiving side deciding a tie over a flag it was never sent.
    /**
     * @internal Public so the fixed-point delivery step is directly
     *           testable without constructing a live amphp WebSocket connection.
     */
    public function deliverGdkEpochWraps(WebsocketClient $client, SyncSession $session): void
    {
        $gateway = $this->epochGateway();
        $peerDeviceId = $session->peerDeviceId() ?? '';

        $wraps = $gateway instanceof GdkEpochDeliveryGateway
            ? $gateway->pendingWrapsFor($peerDeviceId)
            : [];

        // The header is unconditional — the peer reads it before catch-up and
        // would otherwise block waiting for a phase this side silently
        // skipped. An unbound mailbox is simply a zero-wrap push.
        $client->sendBinary($session->encrypt(json_encode([
            'type' => self::MSG_GDK_EPOCH_PUSH,
            'count' => count($wraps),
        ], JSON_THROW_ON_ERROR)));

        foreach ($wraps as $wrap) {
            $client->sendBinary($session->encrypt($wrap['blob']));
        }

        $this->retireAcknowledgedWraps($client, $session, $gateway, $wraps);
        $this->applyPeerEpochWraps($client, $session, $gateway, $peerDeviceId, $this->laravelSession());

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
        $gateway = $this->epochGateway();
        $laravelSession = $this->laravelSession();

        if (! $gateway instanceof GdkEpochDeliveryGateway || $laravelSession === null) {
            return;
        }

        $gateway->drainInbox($this->userId, $this->localDeviceId, $laravelSession);
    }

    private function laravelSession(): ?LaravelSession
    {
        $container = Container::getInstance();
        if (! $container->bound(LaravelSession::class)) {
            return null;
        }

        return $container->make(LaravelSession::class);
    }

    // Null when the mailbox is unbound, which is an ordinary build rather than
    // a fault: the epoch phase then degrades to a zero-wrap push.
    private function epochGateway(): ?GdkEpochDeliveryGateway
    {
        $container = Container::getInstance();
        if (! $container->bound(RelayMailbox::class)) {
            return null;
        }

        /** @var GdkEpochDeliveryGateway $gateway */
        $gateway = $container->make(GdkEpochDeliveryGateway::class);

        return $gateway;
    }

    // Retires only what the peer said it accounted for. Confirming on the way
    // out instead marked a wrap delivered that a dropped connection meant the
    // peer never saw, and nothing re-sends a fan-out.
    /**
     * @param  list<array{id: int, blob: string}>  $wraps
     */
    private function retireAcknowledgedWraps(
        WebsocketClient $client,
        SyncSession $session,
        ?GdkEpochDeliveryGateway $gateway,
        array $wraps,
    ): void {
        $acknowledged = $this->readAcknowledgedCount($client, $session, count($wraps));

        if (! $gateway instanceof GdkEpochDeliveryGateway) {
            return;
        }

        for ($i = 0; $i < $acknowledged; $i++) {
            $gateway->confirmDelivered($wraps[$i]['id']);
        }
    }

    // The return leg. A wrap the peer pushes is applied here, and one this
    // process cannot open is kept in this device's own inbox rather than
    // acknowledged into nothing.
    private function applyPeerEpochWraps(
        WebsocketClient $client,
        SyncSession $session,
        ?GdkEpochDeliveryGateway $gateway,
        string $peerDeviceId,
        ?LaravelSession $laravelSession,
    ): void {
        $announced = $this->readAnnouncedWrapCount($client, $session);
        $accounted = 0;

        for ($i = 0; $i < $announced; $i++) {
            $message = $this->receiveWithTimeout($client, ProtocolTimings::responderReadSeconds(), 'peer epoch wrap');
            if ($message === null) {
                break;
            }

            $blob = $session->decrypt($message->buffer());

            if (! $gateway instanceof GdkEpochDeliveryGateway || $peerDeviceId === '' || $laravelSession === null) {
                continue;
            }

            if ($gateway->receiveEpochWrap($blob, $this->userId, $peerDeviceId, $this->localDeviceId, $laravelSession)) {
                $accounted++;
            }
        }

        $client->sendBinary($session->encrypt(json_encode([
            'type' => self::MSG_GDK_EPOCH_ACK,
            'count' => $accounted,
        ], JSON_THROW_ON_ERROR)));
    }

    private function readAcknowledgedCount(WebsocketClient $client, SyncSession $session, int $sent): int
    {
        $parsed = $this->readEpochControlMessage($client, $session, 'gdk epoch ack');

        if (($parsed['type'] ?? null) !== self::MSG_GDK_EPOCH_ACK) {
            return 0;
        }

        $count = $parsed['count'] ?? null;

        return is_int($count) ? max(0, min($count, $sent)) : 0;
    }

    private function readAnnouncedWrapCount(WebsocketClient $client, SyncSession $session): int
    {
        $parsed = $this->readEpochControlMessage($client, $session, 'peer gdk epoch push header');

        if (($parsed['type'] ?? null) !== self::MSG_GDK_EPOCH_PUSH) {
            return 0;
        }

        $count = $parsed['count'] ?? null;

        return is_int($count) ? max(0, min($count, InboundGdkWrapDrain::MAX_WRAPS_PER_PASS)) : 0;
    }

    // An unreadable or unparseable control frame means nothing was announced,
    // which is ordinary rather than a failure — the empty array says so.
    /**
     * @return array<string, mixed>
     */
    private function readEpochControlMessage(WebsocketClient $client, SyncSession $session, string $phase): array
    {
        $message = $this->receiveWithTimeout($client, ProtocolTimings::responderReadSeconds(), $phase);
        if ($message === null) {
            return [];
        }

        try {
            $parsed = $this->catchUp->parseControlMessage($session->decrypt($message->buffer()));
        } catch (\UnexpectedValueException) {
            return [];
        }

        if (($parsed['type'] ?? null) === self::MSG_PEER_REVOKED) {
            $this->forgetRevokedPeer($session);

            throw PeerRevokedException::toldByPeer($session->peerDeviceId() ?? '');
        }

        return $parsed;
    }

    // The mirror of tellPeerItIsRevoked(): this device is the one being
    // refused. A peer still recorded as confirmed here would keep the settings
    // screen and the scheduler offering a device that hangs up on every dial.
    private function forgetRevokedPeer(SyncSession $session): void
    {
        $peerDeviceId = $session->peerDeviceId() ?? '';

        $this->registryService->forgetPeerConfirmation($this->userId, $peerDeviceId);

        $this->logger->warning('SyncWebSocketHandler: peer reports this device was removed — local confirmation cleared.', [
            'peer_device_id' => $peerDeviceId,
            'user_id' => $this->userId,
        ]);
    }

    // A peer that stalls (pre-auth slow-loris or mid-stream) cannot pin this
    // fiber indefinitely; on timeout the connection is closed and the throw is
    // rethrown so the caller's try/catch tears down the session.
    /**
     * @param  float  $timeoutSeconds  Idle timeout for this single receive.
     * @param  string  $phase  Human-readable phase label for the timeout error.
     *
     * @throws CancelledException When the peer sends nothing within $timeoutSeconds.
     */
    private function receiveWithTimeout(
        WebsocketClient $client,
        float $timeoutSeconds,
        string $phase,
    ): ?WebsocketMessage {
        try {
            return $client->receive(new TimeoutCancellation($timeoutSeconds));
        } catch (CancelledException|TimeoutException $e) {
            // An expired TimeoutCancellation surfaces as CancelledException
            // carrying TimeoutException as its PREVIOUS, so naming only the
            // latter here never matched: no stalled peer was ever logged and
            // none of them were ever closed.
            $this->logger->warning('SyncWebSocketHandler: receive timed out — closing connection.', [
                'phase' => $phase,
                'timeout_seconds' => $timeoutSeconds,
            ]);
            $client->close();

            throw $e;
        }
    }
}
