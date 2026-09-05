<?php

declare(strict_types=1);

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\UnixAddress;
use Amp\TimeoutException;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Exceptions\PeerRevokedException;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// Revocation was one-sided: the responder said PEER_REVOKED and the initiator
// read it, but nothing said it the other way and nothing here understood being
// told. And an expired TimeoutCancellation arrives as CancelledException with
// the TimeoutException only as its previous, so the catch that closes a
// stalled peer's socket named a type this path never throws.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
function refusedPeerUser(): User
{
    return User::query()->create([
        'username' => 'refused-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// Serves a scripted reply list, then a clean disconnect. '__CANCELLED__' is
// what amphp really throws when a TimeoutCancellation expires.
/**
 * @param  list<WebsocketMessage|string>  $inbound
 */
function refusedPeerFakeClient(array $inbound = []): WebsocketClient
{
    return new class($inbound) implements IteratorAggregate, WebsocketClient
    {
        public bool $wasClosed = false;

        /** @var list<string> */
        public array $sentBinary = [];

        private int $cursor = 0;

        /** @param list<WebsocketMessage|string> $inbound */
        public function __construct(private array $inbound) {}

        public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
        {
            $next = $this->inbound[$this->cursor++] ?? null;

            if ($next === '__CANCELLED__') {
                throw new CancelledException(new TimeoutException('Operation timed out'));
            }

            return $next instanceof WebsocketMessage ? $next : null;
        }

        public function getId(): int
        {
            return 1;
        }

        public function getLocalAddress(): SocketAddress
        {
            return new UnixAddress('test');
        }

        public function getRemoteAddress(): SocketAddress
        {
            return new UnixAddress('test');
        }

        public function getTlsInfo(): ?TlsInfo
        {
            return null;
        }

        public function getCloseInfo(): WebsocketCloseInfo
        {
            throw new LogicException('not used in this test');
        }

        public function isCompressionEnabled(): bool
        {
            return false;
        }

        public function sendText(string $data): void
        {
            throw new LogicException('not used in this test');
        }

        public function sendBinary(string $data): void
        {
            $this->sentBinary[] = $data;
        }

        public function streamText(ReadableStream $stream): void
        {
            throw new LogicException('not used in this test');
        }

        public function streamBinary(ReadableStream $stream): void
        {
            throw new LogicException('not used in this test');
        }

        public function ping(): void {}

        public function getCount(WebsocketCount $type): int
        {
            return 0;
        }

        public function getTimestamp(WebsocketTimestamp $type): float
        {
            return \NAN;
        }

        public function isClosed(): bool
        {
            return $this->wasClosed;
        }

        public function close(int $code = 1000, string $reason = ''): void
        {
            $this->wasClosed = true;
        }

        public function onClose(Closure $onClose): void {}

        public function getIterator(): Traversable
        {
            return new ArrayIterator([]);
        }
    };
}

function refusedPeerRegistryRow(
    DatabaseManager $db,
    int $userId,
    string $deviceId,
    string $x25519PublicKeyHex,
    bool $isSelf,
    ?string $confirmedAt = '2026-08-27T10:05:00Z',
): void {
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => $x25519PublicKeyHex,
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-08-27T10:00:00Z',
        'confirmed_at' => $confirmedAt,
        'last_seen_at' => null,
        'created_at' => '2026-08-27T10:00:00Z',
        'updated_at' => '2026-08-27T10:00:00Z',
    ]);
}

function refusedPeerHandler(int $userId, string $localDeviceId, string $localSecret, string $localPublic): SyncWebSocketHandler
{
    return new SyncWebSocketHandler(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        framer: new TransportFramer,
        catchUp: app(PeerCatchUpExchanger::class),
        db: app(DatabaseManager::class),
        clock: app(Clock::class),
        logger: app(LoggerInterface::class),
        localStaticSecret: $localSecret,
        localStaticPublic: $localPublic,
        localDeviceId: $localDeviceId,
        userId: $userId,
    );
}

function refusedPeerBareSyncSession(): SyncSession
{
    $db = app(DatabaseManager::class);

    return new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: []),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
    );
}

/**
 * @return array{0: SyncSession, 1: NoiseSession} [the responder's authenticated session, the peer's Noise session]
 */
function refusedPeerAuthenticatedSession(int $userId, string $localDeviceId, string $localSecret, string $localPublic, string $peerSecret, string $peerPublic): array
{
    $initHs = NoiseHandshakeState::initIkInitiator($peerSecret, $peerPublic, $localPublic);
    $respHs = NoiseHandshakeState::initIkResponder($localSecret, $localPublic);

    $respHs->readMessage($initHs->writeMessage(''));
    $initHs->readMessage($respHs->writeMessage(''));

    [$respSend, $respRecv, $peerStaticToResp] = $respHs->split();
    [$initSend, $initRecv, $peerStaticToInit] = $initHs->split();

    $session = refusedPeerBareSyncSession();
    expect($session->authenticate(new NoiseSession($respSend, $respRecv, $peerStaticToResp), $userId, $localDeviceId))
        ->toBeTrue();

    return [$session, new NoiseSession($initSend, $initRecv, $peerStaticToInit)];
}

// Both halves of a completed Noise handshake WITHOUT asking the registry to
// admit anybody, which is the state a refusal is decided in.
/**
 * @return array{0: NoiseSession, 1: NoiseSession} [the responder's, the initiator's]
 */
function refusedPeerNoisePair(string $localSecret, string $localPublic, string $peerSecret, string $peerPublic): array
{
    $initHs = NoiseHandshakeState::initIkInitiator($peerSecret, $peerPublic, $localPublic);
    $respHs = NoiseHandshakeState::initIkResponder($localSecret, $localPublic);

    $respHs->readMessage($initHs->writeMessage(''));
    $initHs->readMessage($respHs->writeMessage(''));

    [$respSend, $respRecv, $peerStaticToResp] = $respHs->split();
    [$initSend, $initRecv, $peerStaticToInit] = $initHs->split();

    return [
        new NoiseSession($respSend, $respRecv, $peerStaticToResp),
        new NoiseSession($initSend, $initRecv, $peerStaticToInit),
    ];
}

// A phone that has confirmed while this desktop's own confirm is still in
// flight fails the admission gate on the very same branch a removed device
// does. Told it was revoked, the phone clears its confirmation of this desktop
// for good, and a ceremony that was minutes from finishing cannot resume.
it('says nothing about a removal to a device it never admitted', function (): void {
    $user = refusedPeerUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $desktopKp = sodium_crypto_kx_keypair();
    $desktopSecret = sodium_crypto_kx_secretkey($desktopKp);
    $desktopPublic = sodium_crypto_kx_publickey($desktopKp);
    $phoneKp = sodium_crypto_kx_keypair();
    $phoneSecret = sodium_crypto_kx_secretkey($phoneKp);
    $phonePublic = sodium_crypto_kx_publickey($phoneKp);

    // Self only. The phone holds no row here at all, which is what a pairing
    // this side has not finished looks like from the registry.
    refusedPeerRegistryRow($db, $userId, 'desktop-self', sodium_bin2hex($desktopPublic), true);

    [$responderNoise] = refusedPeerNoisePair($desktopSecret, $desktopPublic, $phoneSecret, $phonePublic);

    $client = refusedPeerFakeClient();
    $handler = refusedPeerHandler($userId, 'desktop-self', $desktopSecret, $desktopPublic);

    $handler->tellPeerItIsRevoked($client, $responderNoise);

    expect($client->sentBinary)->toBe(
        [],
        'a device this registry has never admitted was not removed, and the peer treats being told so as final',
    );
});

it('still tells a device it did remove that the trust is gone', function (): void {
    $user = refusedPeerUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $desktopKp = sodium_crypto_kx_keypair();
    $desktopSecret = sodium_crypto_kx_secretkey($desktopKp);
    $desktopPublic = sodium_crypto_kx_publickey($desktopKp);
    $phoneKp = sodium_crypto_kx_keypair();
    $phoneSecret = sodium_crypto_kx_secretkey($phoneKp);
    $phonePublic = sodium_crypto_kx_publickey($phoneKp);

    refusedPeerRegistryRow($db, $userId, 'desktop-self', sodium_bin2hex($desktopPublic), true);
    refusedPeerRegistryRow($db, $userId, 'phone-peer', sodium_bin2hex($phonePublic), false, null);

    [$responderNoise, $phoneNoise] = refusedPeerNoisePair($desktopSecret, $desktopPublic, $phoneSecret, $phonePublic);

    $client = refusedPeerFakeClient();
    $handler = refusedPeerHandler($userId, 'desktop-self', $desktopSecret, $desktopPublic);

    $handler->tellPeerItIsRevoked($client, $responderNoise);

    expect($client->sentBinary)->toHaveCount(
        1,
        'a removed device told nothing goes on describing itself as connected and synced',
    );

    /** @var array<string, mixed> $notice */
    $notice = json_decode($phoneNoise->decrypt($client->sentBinary[0]), true, 512, JSON_THROW_ON_ERROR);

    expect($notice['type'] ?? null)->toBe(SyncWebSocketHandler::MSG_PEER_REVOKED);
});

it('drops its confirmation of a peer that tells it the trust is gone', function (): void {
    $user = refusedPeerUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $desktopKp = sodium_crypto_kx_keypair();
    $desktopSecret = sodium_crypto_kx_secretkey($desktopKp);
    $desktopPublic = sodium_crypto_kx_publickey($desktopKp);
    $phoneKp = sodium_crypto_kx_keypair();
    $phoneSecret = sodium_crypto_kx_secretkey($phoneKp);
    $phonePublic = sodium_crypto_kx_publickey($phoneKp);

    refusedPeerRegistryRow($db, $userId, 'desktop-self', sodium_bin2hex($desktopPublic), true);
    refusedPeerRegistryRow($db, $userId, 'phone-peer', sodium_bin2hex($phonePublic), false);

    [$session, $phoneNoise] = refusedPeerAuthenticatedSession(
        $userId,
        'desktop-self',
        $desktopSecret,
        $desktopPublic,
        $phoneSecret,
        $phonePublic,
    );

    // The phone's own gate refused this desktop, so the first thing it says
    // over the completed handshake is that it no longer confirms us.
    $revoked = WebsocketMessage::fromBinary($phoneNoise->encrypt(json_encode(
        ['type' => SyncWebSocketHandler::MSG_PEER_REVOKED],
        JSON_THROW_ON_ERROR,
    )));

    $client = refusedPeerFakeClient([$revoked]);
    $handler = refusedPeerHandler($userId, 'desktop-self', $desktopSecret, $desktopPublic);

    expect(fn () => $handler->deliverGdkEpochWraps($client, $session))
        ->toThrow(PeerRevokedException::class);

    expect(app(DeviceRegistryService::class)->isStillConfirmed($userId, 'phone-peer'))
        ->toBeFalse('a peer that has stopped confirming this device must stop being offered as one it syncs with');
});

it('closes the socket of a peer whose read really does time out', function (): void {
    $user = refusedPeerUser();
    $userId = (int) $user->id;

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $desktopKp = sodium_crypto_kx_keypair();
    $desktopSecret = sodium_crypto_kx_secretkey($desktopKp);
    $desktopPublic = sodium_crypto_kx_publickey($desktopKp);
    $phoneKp = sodium_crypto_kx_keypair();
    $phoneSecret = sodium_crypto_kx_secretkey($phoneKp);
    $phonePublic = sodium_crypto_kx_publickey($phoneKp);

    refusedPeerRegistryRow($db, $userId, 'desktop-self', sodium_bin2hex($desktopPublic), true);
    refusedPeerRegistryRow($db, $userId, 'phone-peer', sodium_bin2hex($phonePublic), false);

    [$session] = refusedPeerAuthenticatedSession(
        $userId,
        'desktop-self',
        $desktopSecret,
        $desktopPublic,
        $phoneSecret,
        $phonePublic,
    );

    $client = refusedPeerFakeClient(['__CANCELLED__']);
    $handler = refusedPeerHandler($userId, 'desktop-self', $desktopSecret, $desktopPublic);

    expect(fn () => $handler->deliverGdkEpochWraps($client, $session))
        ->toThrow(CancelledException::class);

    expect($client->wasClosed)
        ->toBeTrue('a peer that stalls past the read bound must have its socket closed, not merely be stepped over');
});
