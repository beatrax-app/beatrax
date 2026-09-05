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
     * @return bool `true` on a completed catch-up exchange; `false` for any
     *              retryable outcome. An unconfirmed peer key still throws.
     */
    public function syncOnce(string $host, int $port, DeviceIdentityDto $identity, Session $session): bool
    {
        $peerStaticHex = $this->resolvePeerStaticKeyHex($identity);
        if ($peerStaticHex === null) {
            $this->logger?->info('LanSyncClient: no confirmed LAN peer key yet — skipping (retryable).');

            return false;
        }

        return $this->runExchange($host, $port, $identity, $session, $peerStaticHex);
    }

    private function runExchange(
        string $host,
        int $port,
        DeviceIdentityDto $identity,
        Session $session,
        string $peerStaticHex,
    ): bool {
        $uri = "ws://{$host}:{$port}/";
        $connection = null;

        try {
            $connection = connect($uri, new TimeoutCancellation(ProtocolTimings::SYNC_DIAL_SECONDS));

            $noiseSession = $this->performHandshake($connection, $identity, $peerStaticHex);

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
            $this->exchangeGdkEpochWraps($connection, $syncSession, $identity, $session);

            $this->runCatchUp($connection, $syncSession, $identity->userId, $identity->deviceId, $deviceKeys);

            $syncSession->close();

            return true;
        } catch (LanSyncException $e) {
            // An incomplete dial is the same condition as the block below and
            // rethrowing it put an error page in front of a reader whose other
            // device had merely gone to sleep. A revocation cannot be retried
            // either, so both answer false and only a refusal still raises.
            if ($e->isDialIncomplete()) {
                $this->logger?->info('LanSyncClient: LAN dial did not complete (retryable).', [
                    'reason' => $e::class,
                ]);
            } elseif ($e->isPeerRevocation()) {
                $this->forgetRevokedPeer($identity->userId, $this->resolvePeerDeviceId($identity));
            } else {
                throw $e;
            }

            return false;
        } catch (WebsocketConnectException|CancelledException|TimeoutException $e) {
            // The first LAN connect per install can hit the iOS Local Network
            // Privacy gate, which reads as a clean timeout or refusal before
            // the OS grants access. Retryable, never a thrown fatal.
            $this->logger?->info('LanSyncClient: LAN dial did not complete (retryable).', [
                'reason' => $e::class,
            ]);

            return false;
        } finally {
            $connection?->close();
        }
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
        } catch (\Throwable $e) {
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

    private function resolvePeerStaticKeyHex(DeviceIdentityDto $identity): ?string
    {
        $confirmed = $this->registryService->deviceX25519Keys($identity->userId);
        unset($confirmed[$identity->deviceId]);

        $values = array_values($confirmed);

        return $values[0] ?? null;
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
     */
    private function runCatchUp(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        int $userId,
        string $localDeviceId,
        array $deviceKeys,
    ): void {
        // Named, not defaulted: the watermark is per peer, and the empty id an
        // unnamed caller passes reads back as (0, 0) — the phone then asked
        // this peer for its whole history on every connect.
        $myReq = $this->catchUp->buildRequest($userId, $localDeviceId, $syncSession->peerDeviceId() ?? '');
        $connection->sendBinary($syncSession->encrypt(json_encode($myReq, JSON_THROW_ON_ERROR)));

        $respMsg = $this->receiveWithTimeout($connection, 'catch-up response');
        if ($respMsg === null) {
            return;
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
            return;
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
    ): void {
        $peerDeviceId = $this->resolvePeerDeviceId($identity);

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

    // The one peer this client dials, addressed by the same registry the
    // static key came from so the two can never name different devices.
    private function resolvePeerDeviceId(DeviceIdentityDto $identity): string
    {
        $confirmed = $this->registryService->deviceX25519Keys($identity->userId);
        unset($confirmed[$identity->deviceId]);

        $deviceIds = array_keys($confirmed);

        return $deviceIds === [] ? '' : $deviceIds[0];
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
