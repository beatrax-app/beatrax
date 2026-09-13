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
use Modules\Mobile\Internal\Exceptions\LanSyncException;
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
use Modules\Sync\Public\Transport\ProtocolTimings;
use Psr\Log\LoggerInterface;
use Throwable;

use function Amp\Websocket\Client\connect;

/**
 * @link ../../../../.docs/features/mobile/mobile-initial-sync-gate.md
 */
final readonly class LanSyncClient
{
    public function __construct(
        private DeviceRegistryService $registryService,
        private DeviceKeySigner $signer,
        private TransportFramer $framer,
        private PeerCatchUpExchanger $catchUp,
        private DatabaseManager $db,
        private Clock $clock,
        private MergeRulesRegistry $rules,
        private GdkEpochDeliveryGateway $epochDelivery,
        private ?SearchIndexWriterContract $searchWriter = null,
        private ?LoggerInterface $logger = null,
    ) {}

    /**
     * @throws LanSyncException when this device's own gate refuses the peer.
     */
    public function syncOnce(PeerDial $dial, DeviceIdentityDto $identity, Session $session): LanDialOutcome
    {
        $peer = $this->confirmedPeer($identity, $dial->deviceId);
        if ($peer === null) {
            $this->logger?->info('LanSyncClient: no confirmed key-agreement key for the peer being dialled — skipping (retryable).', [
                'peer_device_id' => $dial->deviceId,
            ]);

            return LanDialOutcome::NotReached;
        }

        try {
            $connection = connect(
                sprintf('ws://%s:%s/', $dial->host, $dial->port),
                new TimeoutCancellation(ProtocolTimings::SYNC_DIAL_SECONDS),
            );
        } catch (WebsocketConnectException|CancelledException|TimeoutException $e) {
            // The first LAN connect per install can hit the iOS Local Network
            // Privacy gate, which reads as a clean timeout or refusal before
            // the OS grants access. Retryable, never a thrown fatal.
            $this->logger?->info('LanSyncClient: LAN dial did not complete (retryable).', [
                'reason' => $e::class,
            ]);

            return LanDialOutcome::NotReached;
        }

        try {
            return $this->runExchange($connection, $identity, $session, $peer);
        } finally {
            $connection->close();
        }
    }

    /**
     * @internal Public so a whole exchange is testable without a live amphp
     *           dial. The dial itself stays in syncOnce().
     *
     * @throws LanSyncException when this device's own gate refuses the peer.
     */
    public function runExchange(
        WebsocketConnection $connection,
        DeviceIdentityDto $identity,
        Session $session,
        ConfirmedLanPeer $peer,
    ): LanDialOutcome {
        $syncSession = null;
        $admitted = false;

        try {
            $noiseSession = $this->performHandshake($connection, $identity, $peer->staticKeyHex);

            [$syncSession, $deviceKeys] = $this->buildSyncSession($identity);

            $admitted = $syncSession->authenticate($noiseSession, $identity->userId, $identity->deviceId);
            if (! $admitted) {
                // Say WHY before hanging up, exactly as the responder does when
                // ITS gate refuses. Told nothing, a desktop this phone has
                // stopped confirming kept describing itself as synced.
                $this->tellPeerItIsRevoked($connection, $noiseSession);

                throw LanSyncException::peerFailedConfirmedDeviceGate();
            }

            // Keys BEFORE the data they decrypt. Drained after catch-up, the
            // first sync applied the desktop's whole encrypted history against
            // an empty keyring and quarantined it, with no replay path.
            $this->exchangeGdkEpochWraps($connection, $syncSession, $identity, $session, $peer->deviceId);

            // A peer that closed before its own CATCH_UP_COMPLETE told this
            // device nothing about what it holds, and calling that Synced put
            // "everything is up to date" under a tick that exchanged nothing.
            $outcome = $this->runCatchUp($connection, $syncSession, $identity->userId, $identity->deviceId, $deviceKeys)
                ? LanDialOutcome::Synced
                : LanDialOutcome::NotReached;
        } catch (LanSyncException $e) {
            $outcome = $this->readRefusal($e, $identity, $peer->deviceId);
        } catch (WebsocketConnectException|CancelledException|TimeoutException $e) {
            // A stall or a hang-up mid-exchange, which the same OS gate can
            // produce on the first LAN connect an install ever makes.
            // Retryable, never a thrown fatal.
            $this->logger?->info('LanSyncClient: LAN exchange did not complete (retryable).', [
                'reason' => $e::class,
            ]);

            $outcome = LanDialOutcome::NotReached;
        } catch (Throwable $e) {
            // The responder wraps its own exchange the same way. A peer that
            // hangs up mid-frame throws WebsocketClosedException, which reached
            // past syncOnce() and took the relay leg, the epoch inbox drain and
            // the held-entry recovery down with one desktop's dial.
            $this->logger?->warning('LanSyncClient: the exchange broke after the peer answered.', [
                'reason' => $e::class,
            ]);

            $outcome = LanDialOutcome::NotSecured;
        } finally {
            // The row an admitted session opened says `active` until close()
            // writes what it leaves to, and nothing else ever does. Left on a
            // failed ending, the phone went on reporting an exchange as under
            // way for as long as a reader kept looking at the screen.
            if ($admitted) {
                $syncSession?->close();
            }
        }

        return $outcome;
    }

    // Every refusal here reached the peer: the WebSocket was open and the Noise
    // session is what did not open. Rethrowing an incomplete dial once put an
    // error page in front of a reader whose other device had merely gone to
    // sleep, so only this device's own gate still raises.
    /**
     * @throws LanSyncException
     */
    private function readRefusal(LanSyncException $e, DeviceIdentityDto $identity, string $peerDeviceId): LanDialOutcome
    {
        if ($e->isPeerRevocation()) {
            $this->forgetRevokedPeer($identity->userId, $peerDeviceId);
        } elseif (! $e->isDialIncomplete()) {
            throw $e;
        }

        $this->logger?->info('LanSyncClient: the peer answered and no secure session opened.', [
            'reason' => $e->reason(),
        ]);

        return LanDialOutcome::NotSecured;
    }

    // Best-effort notice sent on the raw Noise session, since SyncSession is
    // deliberately unauthenticated at this point, and never allowed to throw —
    // the connection is being torn down either way.
    /**
     * @internal Public so the notice is testable without a live amphp dial.
     */
    public function tellPeerItIsRevoked(WebsocketConnection $connection, NoiseSession $noiseSession): void
    {
        try {
            $connection->sendBinary($noiseSession->encrypt(json_encode(
                ['type' => GdkEpochDeliveryGateway::MSG_PEER_REVOKED],
                JSON_THROW_ON_ERROR,
            )));
        } catch (Throwable $e) {
            $this->logger?->info('LanSyncClient: could not tell the peer it was revoked.', [
                'reason' => $e::class,
            ]);
        }
    }

    // The device that said it and no other. Sweeping every non-self row took
    // a second desktop's confirmation down with the first one's notice, and
    // nothing but a fresh ceremony ever puts a confirmation back.
    /**
     * @internal Public so the effect is testable without a live amphp dial.
     */
    public function forgetRevokedPeer(int $userId, string $peerDeviceId): void
    {
        $this->registryService->forgetPeerConfirmation($userId, $peerDeviceId);

        $this->logger?->warning('LanSyncClient: peer reports this device was removed — local confirmation cleared.', [
            'user_id' => $userId,
            'peer_device_id' => $peerDeviceId,
        ]);
    }

    // Looked up by the id of the device being dialled, never taken as the first
    // entry of the map. Noise IK names the responder before msg1, so the wrong
    // entry is not a slower handshake — it is one that cannot complete, and the
    // peers past the first were unreachable for as long as this read the map.
    private function confirmedPeer(DeviceIdentityDto $identity, string $peerDeviceId): ?ConfirmedLanPeer
    {
        $confirmed = $this->registryService->deviceX25519Keys($identity->userId);
        unset($confirmed[$identity->deviceId]);

        return ConfirmedLanPeer::fromConfirmed($confirmed, $peerDeviceId);
    }

    /**
     * @throws LanSyncException on premature disconnect.
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

        $msg2Message = $connection->receive(new TimeoutCancellation(ProtocolTimings::HANDSHAKE_SECONDS));
        if ($msg2Message === null) {
            throw LanSyncException::peerDisconnectedBeforeHandshakeMessage('msg2');
        }
        $initHs->readMessage($msg2Message->buffer());

        [$sendCipher, $recvCipher, $peerStaticRevealed] = $initHs->split();

        return new NoiseSession($sendCipher, $recvCipher, $peerStaticRevealed);
    }

    // One connect-time snapshot serves catch-up and replay, so both judge
    // signatures against the same set. Auth is NOT one of them: the handshake
    // answers to deviceX25519Keys(), and a key that reached this map through an
    // introduction has no X25519 half anywhere for a session to match.
    /**
     * @return array{0: SyncSession, 1: array<string, string>}
     */
    private function buildSyncSession(DeviceIdentityDto $identity): array
    {
        $deviceKeys = $this->registryService->signatureVerificationKeys($identity->userId);

        $replayer = new OpLogReplayer(
            db: $this->db,
            deviceKeys: $deviceKeys,
            deviceKeysUserId: $identity->userId,
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

    // The initiator half of the bilateral exchange, mirroring the responder
    // sequence: request, their response and frames, their request, our
    // response and frames, then a CATCH_UP_COMPLETE each way.
    /**
     * @param  array<string, string>  $deviceKeys  Connect-time confirmed
     *                                             Ed25519 key snapshot.
     * @return bool Whether the peer carried the exchange through to its own
     *              CATCH_UP_COMPLETE. A false is a peer that hung up part way,
     *              which is the one thing a null read here can mean.
     */
    private function runCatchUp(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        int $userId,
        string $localDeviceId,
        array $deviceKeys,
    ): bool {
        // Named, not defaulted: the watermark is per peer, and the empty id an
        // unnamed caller passes reads back as (0, 0) — the phone then asked
        // this peer for its whole history on every connect.
        $myReq = $this->catchUp->buildRequest($userId, $localDeviceId, $syncSession->peerDeviceId() ?? '');
        $connection->sendBinary($syncSession->encrypt(json_encode($myReq, JSON_THROW_ON_ERROR)));

        $respMsg = $this->receiveWithTimeout($connection, 'catch-up response');
        if ($respMsg === null) {
            return false;
        }

        $resp = $this->catchUp->parseControlMessage($syncSession->decrypt($respMsg->buffer()));

        // Before the frames, not after: the frame loop can end early on a
        // timeout, and the one thing that says WHY this exchange came back thin
        // is the list the peer sent with the count.
        $this->catchUp->recordIntroductions($userId, $resp, $syncSession->peerDeviceId() ?? '');

        $declaredFrameCount = isset($resp['frame_count']) && is_int($resp['frame_count']) ? $resp['frame_count'] : 0;
        $frameCount = max(0, min($declaredFrameCount, GdkEpochDeliveryGateway::MAX_CATCHUP_FRAMES));

        for ($i = 0; $i < $frameCount; $i++) {
            $frameMsg = $this->receiveWithTimeout($connection, 'catch-up frame');
            if ($frameMsg === null) {
                break;
            }

            $syncSession->receiveOps($frameMsg->buffer(), $userId, $deviceKeys);
        }

        $peerReqMsg = $this->receiveWithTimeout($connection, 'catch-up request');
        if ($peerReqMsg === null) {
            return false;
        }

        $peerReq = $this->catchUp->parseControlMessage($syncSession->decrypt($peerReqMsg->buffer()));
        [$delta, $myResp] = $this->catchUp->answer($userId, $peerReq, $syncSession->peerDeviceId() ?? '');
        $connection->sendBinary($syncSession->encrypt(json_encode($myResp, JSON_THROW_ON_ERROR)));

        // Iterated, not collected: each frame is built as it is sent, so this
        // phone's own history crosses the wire without ever being resident at
        // once — the shape that fatalled the 128 MB ceiling at 50,000 entries.
        foreach ($delta as $frame) {
            $connection->sendBinary($syncSession->encrypt($frame));
        }

        $peerCompleteMsg = $this->receiveWithTimeout($connection, 'catch-up complete');
        if ($peerCompleteMsg !== null) {
            $syncSession->decrypt($peerCompleteMsg->buffer());
        }

        $connection->sendBinary($syncSession->encrypt(
            json_encode($this->catchUp->buildComplete(), JSON_THROW_ON_ERROR)
        ));

        return $peerCompleteMsg !== null;
    }

    // A wrap reaches the delivery gateway only from inside this still-open,
    // already-authenticated session. Never wire an unauthenticated source
    // into this path. Both legs run: what the desktop holds for this phone,
    // and what this phone holds for the desktop.
    public function exchangeGdkEpochWraps(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        DeviceIdentityDto $identity,
        Session $session,
        string $peerDeviceId,
    ): void {
        $this->receiveGdkEpochWraps($connection, $syncSession, $identity, $peerDeviceId, $session);
        $this->pushGdkEpochWraps($connection, $syncSession, $peerDeviceId);

        // A wrap this phone could not open is RETAINED in its own inbox rather
        // than dropped, and this pass — holding the app-lock key the retaining
        // one lacked — is the only thing that comes back for it. Without it the
        // responder half drained and the initiator half never did.
        $this->epochDelivery->drainInbox($identity->userId, $identity->deviceId, $session);
    }

    /**
     * @internal Public so the receive step is directly testable without a live
     *           amphp WebSocket connection.
     */
    public function receiveGdkEpochWraps(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        DeviceIdentityDto $identity,
        string $peerDeviceId,
        Session $session,
    ): void {
        $announced = $this->readEpochPushCount($connection, $syncSession);
        $accounted = 0;

        for ($i = 0; $i < $announced; $i++) {
            $message = $this->receiveWithTimeout($connection, 'gdk epoch wrap');
            if ($message === null) {
                break;
            }

            $decrypted = $syncSession->decrypt($message->buffer());

            if ($this->isEpochWrap($decrypted) && $peerDeviceId !== '' && $this->epochDelivery->receiveEpochWrap(
                $decrypted,
                $identity->userId,
                $peerDeviceId,
                $identity->deviceId,
                $session,
            )) {
                $accounted++;
            }
        }

        // Acknowledged only for what is durably accounted for, so the desktop
        // retires exactly those rows. A wrap this process could not open is
        // kept in this device's own inbox by the gateway, not dropped.
        $connection->sendBinary($syncSession->encrypt(json_encode([
            'type' => GdkEpochDeliveryGateway::MSG_EPOCH_ACK,
            'count' => $accounted,
        ], JSON_THROW_ON_ERROR)));
    }

    // The return leg this exchange lacked. Without it the desktop decided the
    // blind-index tie over a keyed-rows flag the phone never sent, and a phone
    // that holds the ledger kept a key no other device ever learned.
    private function pushGdkEpochWraps(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        string $peerDeviceId,
    ): void {
        $wraps = $peerDeviceId === '' ? [] : $this->epochDelivery->pendingWrapsFor($peerDeviceId);

        $connection->sendBinary($syncSession->encrypt(json_encode([
            'type' => GdkEpochDeliveryGateway::MSG_EPOCH_PUSH,
            'count' => count($wraps),
        ], JSON_THROW_ON_ERROR)));

        foreach ($wraps as $wrap) {
            $connection->sendBinary($syncSession->encrypt($wrap['blob']));
        }

        $acknowledged = $this->readAcknowledgedCount($connection, $syncSession, count($wraps));

        for ($i = 0; $i < $acknowledged; $i++) {
            $this->epochDelivery->confirmDelivered($wraps[$i]['id']);
        }
    }

    private function readAcknowledgedCount(WebsocketConnection $connection, SyncSession $syncSession, int $sent): int
    {
        $parsed = $this->readControlMessage($connection, $syncSession);

        if (($parsed['type'] ?? null) !== GdkEpochDeliveryGateway::MSG_EPOCH_ACK) {
            return 0;
        }

        $count = $parsed['count'] ?? null;

        return is_int($count) ? max(0, min($count, $sent)) : 0;
    }

    // Ending the loop on the first unrecognised frame discarded every wrap
    // queued behind it, and the desktop had already marked them delivered.
    private function isEpochWrap(string $decrypted): bool
    {
        try {
            $parsed = $this->catchUp->parseControlMessage($decrypted);
        } catch (\UnexpectedValueException) {
            return false;
        }

        return ($parsed['type'] ?? null) === GdkEpochDeliveryGateway::MSG_EPOCH_WRAP;
    }

    // The phase runs before catch-up, so its end cannot be inferred from a
    // read timeout the way a trailing phase's could — that would consume the
    // catch-up request queued behind it. Hence an announced count.
    private function readEpochPushCount(WebsocketConnection $connection, SyncSession $syncSession): int
    {
        $parsed = $this->readControlMessage($connection, $syncSession);

        // The peer no longer confirms this device, so there is nothing left
        // to sync and the local trust record must stop saying otherwise.
        if (($parsed['type'] ?? null) === GdkEpochDeliveryGateway::MSG_PEER_REVOKED) {
            throw LanSyncException::peerRevokedThisDevice();
        }

        if (($parsed['type'] ?? null) !== GdkEpochDeliveryGateway::MSG_EPOCH_PUSH) {
            return 0;
        }

        $count = $parsed['count'] ?? null;

        return is_int($count) ? max(0, min($count, GdkEpochDeliveryGateway::maxWrapsPerPass())) : 0;
    }

    // An unreadable or unparseable header means nothing was pushed, which is
    // ordinary rather than a failure — the empty array says so.
    /**
     * @return array<string, mixed>
     */
    private function readControlMessage(WebsocketConnection $connection, SyncSession $syncSession): array
    {
        $message = $this->receiveWithTimeout($connection, 'gdk epoch push header');

        if ($message === null) {
            return [];
        }

        try {
            return $this->catchUp->parseControlMessage($syncSession->decrypt($message->buffer()));
        } catch (\UnexpectedValueException) {
            return [];
        }
    }

    // Rethrown rather than folded into the null a clean disconnect returns:
    // a stall mid-phase is not "nothing more to read", and syncOnce()'s
    // retryable false comes from runExchange() catching it.
    /**
     * @throws CancelledException When the peer sends nothing within the bound.
     */
    private function receiveWithTimeout(WebsocketConnection $connection, string $phase): ?WebsocketMessage
    {
        try {
            return $connection->receive(new TimeoutCancellation(ProtocolTimings::initiatorReadSeconds()));
        } catch (CancelledException|TimeoutException $e) {
            // An expired TimeoutCancellation surfaces as CancelledException
            // carrying TimeoutException as its PREVIOUS, so naming only the
            // latter here never matched and no real stall was ever logged.
            $this->logger?->info('LanSyncClient: receive timed out.', [
                'phase' => $phase,
            ]);

            throw $e;
        }
    }
}
