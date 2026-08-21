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
 * @link ../../../../.docs/features/mobile/mobile-initial-sync-gate.md
 */
final class LanSyncClient
{
    private const float CONNECT_TIMEOUT_SECONDS = 5.0;

    private const float READ_TIMEOUT_SECONDS = 15.0;

    private const int MAX_CATCHUP_FRAMES = 100_000;

    // A larger backlog is picked up on the next connect rather than pinning
    // this fiber.
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
            $connection = connect($uri, new TimeoutCancellation(self::CONNECT_TIMEOUT_SECONDS));

            $noiseSession = $this->performHandshake($connection, $identity, $peerStaticHex);

            [$syncSession, $deviceKeys] = $this->buildSyncSession($identity);

            $admitted = $syncSession->authenticate($noiseSession, $identity->userId, $identity->deviceId);
            if (! $admitted) {
                throw LanSyncException::peerFailedConfirmedDeviceGate();
            }

            // Keys BEFORE the data they decrypt. Drained after catch-up, the
            // first sync applied the desktop's whole encrypted history against
            // an empty keyring and quarantined it, with no replay path.
            $this->receiveGdkEpochWraps($connection, $syncSession, $identity->userId, $session);

            $this->runCatchUp($connection, $syncSession, $identity->userId, $identity->deviceId, $deviceKeys);

            $syncSession->close();

            return true;
        } catch (LanSyncException $e) {
            if (! $e->isPeerRevocation()) {
                throw $e;
            }

            // Retrying cannot help — this device was removed on the other
            // side — so stop every surface reporting a peer that refuses it.
            $this->forgetRevokedPeer($identity->userId);

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

    // Every non-self peer, because this client only ever dials one: keeping
    // the row confirmed asserts trust the other side already withdrew.
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

    // One connect-time key snapshot serves auth, catch-up and replay, so all
    // three judge against the same trust set.
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
        $myReq = $this->catchUp->buildRequest($userId, $localDeviceId);
        $connection->sendBinary($syncSession->encrypt(json_encode($myReq, JSON_THROW_ON_ERROR)));

        $respMsg = $this->receiveWithTimeout($connection, 'catch-up response');
        if ($respMsg === null) {
            return;
        }

        $resp = $this->catchUp->parseControlMessage($syncSession->decrypt($respMsg->buffer()));
        $declaredFrameCount = isset($resp['frame_count']) && is_int($resp['frame_count']) ? $resp['frame_count'] : 0;
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

    // A wrap reaches the delivery gateway only from inside this still-open,
    // already-authenticated session. Never wire an unauthenticated source
    // into this path.
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
                // Ending the loop on the first unrecognised frame discarded
                // every wrap queued behind it, and the desktop had already
                // marked them delivered — the keys were simply gone.
                continue;
            }

            // A wire literal because GdkEpochControlHandler, which owns the
            // matching constant, is off-limits to this module.
            if (($parsed['type'] ?? null) !== 'GDK_EPOCH_WRAP') {
                continue;
            }

            $this->epochDelivery->receiveEpochWrap($decrypted, $userId, $session);
        }
    }

    // The phase runs before catch-up, so its end cannot be inferred from a
    // read timeout the way a trailing phase's could — that would consume the
    // catch-up request queued behind it. Hence an announced count.
    private function readEpochPushCount(WebsocketConnection $connection, SyncSession $syncSession): int
    {
        $parsed = $this->readControlMessage($connection, $syncSession);

        // The peer no longer confirms this device, so there is nothing left
        // to sync and the local trust record must stop saying otherwise.
        if (($parsed['type'] ?? null) === 'PEER_REVOKED') {
            throw LanSyncException::peerRevokedThisDevice();
        }

        if (($parsed['type'] ?? null) !== 'GDK_EPOCH_PUSH') {
            return 0;
        }

        $count = $parsed['count'] ?? null;

        return is_int($count) ? max(0, min($count, self::MAX_GDK_WRAPS_PER_CONNECT)) : 0;
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
