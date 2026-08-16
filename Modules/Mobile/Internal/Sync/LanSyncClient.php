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
use Psr\Log\LoggerInterface;

use function Amp\Websocket\Client\connect;

/**
 * @link ../../../../.docs/features/mobile/architecture.md
 */
final class LanSyncClient
{
    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    private const float READ_TIMEOUT_SECONDS = 15.0;

    // Amplification guard mirroring SyncWebSocketHandler's own limit,
    // reused verbatim on the client side.
    private const int MAX_CATCHUP_FRAMES = 100_000;

    // Bounded so an unbounded backlog cannot pin this fiber; a backlog
    // larger than this is picked up on the next connect.
    private const int MAX_GDK_WRAPS_PER_CONNECT = 100;

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
     * @return bool `true` on a completed catch-up exchange; `false` for any
     *              retryable outcome (no confirmed peer key yet, or the
     *              connection/handshake did not complete). The caller
     *              decides whether/when to retry. A genuinely unexpected
     *              failure (the peer's revealed key is not a confirmed
     *              device) still throws - a real security-relevant
     *              rejection, not a transient outcome.
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

    // The dial itself, and what each way it can end means. Split from the
    // precondition above so one method decides whether there is anything to
    // attempt and the other decides what the attempt's outcome was.
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
            $connection = connect($uri, new TimeoutCancellation(self::CONNECT_TIMEOUT_SECONDS));

            $noiseSession = $this->performHandshake($connection, $identity, $peerStaticHex);

            [$syncSession, $deviceKeys] = $this->buildSyncSession($identity);

            $admitted = $syncSession->authenticate($noiseSession, $identity->userId, $identity->deviceId);
            if (! $admitted) {
                throw LanSyncException::peerFailedConfirmedDeviceGate();
            }

            // Keys BEFORE the data they decrypt, inside the still-open Noise
            // session that encrypted them. Consumed after catch-up, the first
            // sync applied the desktop's whole encrypted history against an
            // empty keyring and quarantined it, which has no replay path.
            $this->receiveGdkEpochWraps($connection, $syncSession, $identity->userId, $session);

            $this->runCatchUp($connection, $syncSession, $identity->userId, $identity->deviceId, $deviceKeys);

            $syncSession->close();

            return true;
        } catch (LanSyncException $e) {
            if (! $e->isPeerRevocation()) {
                throw $e;
            }

            // Retrying cannot help: this device has been removed on the other
            // side. Drop the local confirmation so every surface stops
            // reporting a peer that no longer accepts it.
            $this->forgetRevokedPeer($identity->userId);

            return false;
        } catch (WebsocketConnectException|CancelledException|TimeoutException $e) {
            // The FIRST LAN connect per install can hit the iOS Local
            // Network Privacy gate - a clean connect timeout/refused/
            // handshake-stall before the OS grants access. Modelled as
            // retryable, never a thrown fatal.
            $this->logger?->info('LanSyncClient: LAN dial did not complete (retryable).', [
                'reason' => $e::class,
            ]);

            return false;
        } finally {
            $connection?->close();
        }
    }

    // Clears the local confirmation for every non-self peer. This client
    // only ever dials one household peer, and it has just said it no longer
    // knows this device — keeping the row confirmed is a trust claim the
    // other side has already withdrawn.
    private function forgetRevokedPeer(int $userId): void
    {
        $this->db->connection()
            ->table('device_registry')
            ->where('user_id', $userId)
            ->where('is_self', 0)
            ->update(['confirmed_at' => null]);

        $this->logger?->warning('LanSyncClient: peer reports this device was removed — local confirmation cleared.', [
            'user_id' => $userId,
        ]);
    }

    private function resolvePeerStaticKeyHex(DeviceIdentityDto $identity): ?string
    {
        $confirmed = $this->registryService->deviceX25519Keys($identity->userId);
        unset($confirmed[$identity->deviceId]);

        // Single-peer household pairing - take the first confirmed
        // non-self device. Multi-peer selection is out of scope.
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

        $msg2Message = $connection->receive(new TimeoutCancellation(self::READ_TIMEOUT_SECONDS));
        if ($msg2Message === null) {
            throw LanSyncException::peerDisconnectedBeforeHandshakeMessage('msg2');
        }
        $initHs->readMessage($msg2Message->buffer());

        [$sendCipher, $recvCipher, $peerStaticRevealed] = $initHs->split();

        return new NoiseSession($sendCipher, $recvCipher, $peerStaticRevealed);
    }

    // Builds a fresh SyncSession + the connect-time confirmed Ed25519
    // device-key snapshot - the same trust set for auth, catch-up, and
    // replay.
    /**
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

    // Runs the bilateral catch-up exchange as the INITIATOR - the exact
    // mirror image of the responder-side sequence: send our request,
    // receive the desktop's response+frames and its own request, send
    // our response+frames, then exchange CATCH_UP_COMPLETE.
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
        $myReq = $this->catchUp->buildRequest($userId, $localDeviceId);
        $connection->sendBinary($syncSession->encrypt(json_encode($myReq, JSON_THROW_ON_ERROR)));

        $respMsg = $this->receiveWithTimeout($connection, 'catch-up response');
        if ($respMsg === null) {
            return;
        }

        $resp = $this->catchUp->parseControlMessage($syncSession->decrypt($respMsg->buffer()));
        $declaredFrameCount = isset($resp['frame_count']) && is_int($resp['frame_count']) ? $resp['frame_count'] : 0;
        // Clamp the desktop-declared frame_count so a corrupted/malicious
        // response cannot pin this bounded burst.
        $frameCount = max(0, min($declaredFrameCount, self::MAX_CATCHUP_FRAMES));

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
        $peerHlcL = isset($peerReq['hlc_l']) && is_int($peerReq['hlc_l']) ? max(0, $peerReq['hlc_l']) : 0;
        $peerHlcC = isset($peerReq['hlc_c']) && is_int($peerReq['hlc_c']) ? max(0, $peerReq['hlc_c']) : 0;

        $frames = $this->catchUp->opsAfterWatermark($userId, $peerHlcL, $peerHlcC);
        $myResp = $this->catchUp->buildResponse($frames);
        $connection->sendBinary($syncSession->encrypt(json_encode($myResp, JSON_THROW_ON_ERROR)));

        foreach ($frames as $frame) {
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

    // Drains up to MAX_GDK_WRAPS_PER_CONNECT pushed frames. SECURITY: the
    // wrap is routed into the delivery gateway only from inside this
    // still-open, already-authenticated session - never wire an
    // unauthenticated source into this path. Public for direct testability.
    public function receiveGdkEpochWraps(
        WebsocketConnection $connection,
        SyncSession $syncSession,
        int $userId,
        Session $session,
    ): void {
        $announced = $this->readEpochPushCount($connection, $syncSession);

        for ($i = 0; $i < $announced; $i++) {
            $message = $this->receiveWithTimeout($connection, 'gdk epoch wrap');
            if ($message === null) {
                return;
            }

            $decrypted = $syncSession->decrypt($message->buffer());

            try {
                $parsed = $this->catchUp->parseControlMessage($decrypted);
            } catch (\UnexpectedValueException) {
                // Skipped, not fatal. Ending the loop on the first thing this
                // step did not recognise discarded every wrap queued behind
                // it, and the desktop had already marked all of them
                // delivered — the keys were simply gone.
                continue;
            }

            // The literal wire-protocol string mirrors
            // GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP - that class is
            // off-limits to this module directly, so this is not a class
            // reference.
            if (($parsed['type'] ?? null) !== 'GDK_EPOCH_WRAP') {
                continue;
            }

            $this->epochDelivery->receiveEpochWrap($decrypted, $userId, $session);
        }
    }

    // Reads the header that opens the epoch-push phase and returns how many
    // wrap frames follow. The phase runs before catch-up, so its end cannot
    // be inferred from a read timeout the way a trailing phase's could —
    // that would consume the catch-up request queued behind it.
    private function readEpochPushCount(WebsocketConnection $connection, SyncSession $syncSession): int
    {
        $parsed = $this->readControlMessage($connection, $syncSession);

        // Mirrors SyncWebSocketHandler::MSG_PEER_REVOKED. The peer no longer
        // confirms this device, so there is nothing left to sync and the
        // local trust record has to stop saying otherwise.
        if (($parsed['type'] ?? null) === 'PEER_REVOKED') {
            throw LanSyncException::peerRevokedThisDevice();
        }

        // Mirrors SyncWebSocketHandler::MSG_GDK_EPOCH_PUSH — that class is
        // off-limits to this module directly, so this is the wire literal.
        if (($parsed['type'] ?? null) !== 'GDK_EPOCH_PUSH') {
            return 0;
        }

        $count = $parsed['count'] ?? null;

        return is_int($count) ? max(0, min($count, self::MAX_GDK_WRAPS_PER_CONNECT)) : 0;
    }

    // An unreadable or unparseable header means nothing was pushed, which is
    // an ordinary outcome rather than a failure — an empty set says so
    // without the caller having to distinguish the two.
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

    /**
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
