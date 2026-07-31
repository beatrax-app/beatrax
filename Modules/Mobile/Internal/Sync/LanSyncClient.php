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

    // A short, dedicated bound (unlike READ_TIMEOUT_SECONDS's generous
    // catch-up budget): the desktop pushes any pending wraps immediately
    // after catch-up completes, so a short idle bound detects "no more
    // wraps" without stalling every sync behind the full catch-up timeout.
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

            $this->runCatchUp($connection, $syncSession, $identity->userId, $identity->deviceId, $deviceKeys);

            // Consume any pushed GDK_EPOCH_WRAP frames inside the
            // still-open Noise session (they were encrypted with this
            // same session, so decrypting them requires it, not a new
            // connection). Must run BEFORE session->close() below.
            $this->receiveGdkEpochWraps($connection, $syncSession, $identity->userId, $session);

            $syncSession->close();

            return true;
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
        for ($i = 0; $i < self::MAX_GDK_WRAPS_PER_CONNECT; $i++) {
            try {
                $message = $connection->receive(new TimeoutCancellation(self::GDK_WRAP_READ_TIMEOUT_SECONDS));
            } catch (TimeoutException) {
                // No more wraps pending — the desktop's fixed push step is
                // over (it never sends anything further until the phone
                // itself disconnects). Not an error.
                break;
            }

            if ($message === null) {
                break;
            }

            $decrypted = $syncSession->decrypt($message->buffer());

            try {
                $parsed = $this->catchUp->parseControlMessage($decrypted);
            } catch (\UnexpectedValueException) {
                // Malformed frame — the desktop's fixed step only ever
                // sends wrap frames, so treat this as "push is over" rather
                // than throwing.
                break;
            }

            // The literal wire-protocol string mirrors
            // GdkEpochControlHandler::MSG_GDK_EPOCH_WRAP - that class is
            // off-limits to this module directly, so this is not a class
            // reference. A non-wrap message ends this fixed step.
            if (($parsed['type'] ?? null) !== 'GDK_EPOCH_WRAP') {
                break;
            }

            $this->epochDelivery->receiveEpochWrap($decrypted, $userId, $session);
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
