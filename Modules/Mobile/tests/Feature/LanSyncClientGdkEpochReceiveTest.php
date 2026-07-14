<?php

declare(strict_types=1);

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Http\Client\Response;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\UnixAddress;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Sync\LanSyncClient;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

/*
 * LanSyncClientGdkEpochReceiveTest — Task 4 (Phase 15 import-join, G3):
 * LanSyncClient::receiveGdkEpochWraps() consumes a pushed GDK_EPOCH_WRAP
 * frame over a real, already-authenticated Noise IK session and converges
 * the phone's keyring. 6e per 15-import-join-PLAN.md's test-plan.
 *
 * A real loopback WebSocket TCP connection is Manual-Only Verification
 * (LanDirectSessionTest's own documented precedent) — this test instead
 * exercises the SAME in-memory Noise handshake + SyncSession::authenticate()
 * + the new receive step directly, driven through a fake WebsocketConnection
 * (mirrors GdkEpochDeliveryTest's deliveryFakeClient() pattern, adapted to
 * the CLIENT-side WebsocketConnection interface).
 */

/**
 * Minimal WebsocketConnection fake — only receive() is exercised by
 * receiveGdkEpochWraps(); every other interface method throws if called.
 * Messages are served in order; once exhausted, receive() returns null
 * (clean-disconnect ending shape) unless $throwTimeoutAfterExhausted is set.
 */
function lanReceiveFakeConnection(array $messages): WebsocketConnection
{
    return new class($messages) implements IteratorAggregate, WebsocketConnection
    {
        private int $cursor = 0;

        public function __construct(private array $messages) {}

        public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
        {
            if ($this->cursor >= count($this->messages)) {
                return null;
            }

            return $this->messages[$this->cursor++];
        }

        public function getHandshakeResponse(): Response
        {
            throw new LogicException('not used in this test');
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
            throw new LogicException('not used in this test');
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
            return NAN;
        }

        public function isClosed(): bool
        {
            return false;
        }

        public function close(int $code = 1000, string $reason = ''): void {}

        public function onClose(Closure $onClose): void {}

        public function getIterator(): Traversable
        {
            return new ArrayIterator([]);
        }
    };
}

function lanReceiveUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

function lanReceiveBareSyncSession(array $deviceKeys = []): SyncSession
{
    $db = app(DatabaseManager::class);

    return new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: $deviceKeys),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
    );
}

/**
 * @return array{0: NoiseSession, 1: NoiseSession} [phoneSideSession, desktopSideSession]
 */
function lanReceiveNoisePair(string $phoneSecret, string $phonePublic, string $desktopSecret, string $desktopPublic): array
{
    $initHs = NoiseHandshakeState::initIkInitiator($phoneSecret, $phonePublic, $desktopPublic);
    $respHs = NoiseHandshakeState::initIkResponder($desktopSecret, $desktopPublic);

    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$initSend, $initRecv, $peerStaticRevealedToInit] = $initHs->split();
    [$respSend, $respRecv, $peerStaticRevealedToResp] = $respHs->split();

    $phoneNoiseSession = new NoiseSession($initSend, $initRecv, $peerStaticRevealedToInit);
    $desktopNoiseSession = new NoiseSession($respSend, $respRecv, $peerStaticRevealedToResp);

    return [$phoneNoiseSession, $desktopNoiseSession];
}

it('routes a pushed GDK_EPOCH_WRAP frame through the authenticated session and converges the phone keyring', function (): void {
    $user = lanReceiveUser('lan-receive-converge');
    $userId = (int) $user->id;
    test()->actingAs($user);

    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([]);

    // A confirmed desktop peer with a real X25519 keypair (needed so the
    // phone's SyncSession::authenticate() genuinely admits it).
    $desktopKp = sodium_crypto_kx_keypair();
    $desktopSecret = sodium_crypto_kx_secretkey($desktopKp);
    $desktopPublic = sodium_crypto_kx_publickey($desktopKp);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'desktop-peer',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex($desktopPublic),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-14T10:00:00Z',
        'confirmed_at' => '2026-07-14T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-14T10:00:00Z',
        'updated_at' => '2026-07-14T10:00:00Z',
    ]);

    [$phoneNoiseSession, $desktopNoiseSession] = lanReceiveNoisePair(
        sodium_hex2bin($phone->x25519SecretKeyHex),
        sodium_hex2bin($phone->x25519PublicKeyHex),
        $desktopSecret,
        $desktopPublic,
    );

    $phoneSyncSession = lanReceiveBareSyncSession();
    $admitted = $phoneSyncSession->authenticate($phoneNoiseSession, $userId, $phone->deviceId);
    expect($admitted)->toBeTrue('the phone must authenticate the desktop as a confirmed peer before any wrap is trusted (WR-07)');

    // The desktop builds + pushes a real sealed-box wrap addressed to the
    // phone, encrypted with ITS OWN send cipher (the phone decrypts with
    // the matching recv cipher on its own session — Noise bidirectional
    // ciphers, not a shared key).
    $rawEpochKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $recipientPub = sodium_hex2bin($phone->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(1, $rawEpochKey, $recipientPub, $phone->deviceId);
    $pushedFrame = $desktopNoiseSession->encrypt(json_encode($wrap, JSON_THROW_ON_ERROR));

    $connection = lanReceiveFakeConnection([WebsocketMessage::fromBinary($pushedFrame)]);

    /** @var LanSyncClient $client */
    $client = app(LanSyncClient::class);
    $client->receiveGdkEpochWraps($connection, $phoneSyncSession, $userId, $session);

    $loaded = $keyring->loadKeyring($userId, $session);
    expect($loaded->keyFor(1))->toBe(sodium_bin2hex($rawEpochKey), 'the phone keyring must converge after the receive step');
});

it('skips a malformed pushed frame without throwing and without appending anything', function (): void {
    $user = lanReceiveUser('lan-receive-malformed');
    $userId = (int) $user->id;
    test()->actingAs($user);

    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    $desktopKp = sodium_crypto_kx_keypair();
    $desktopSecret = sodium_crypto_kx_secretkey($desktopKp);
    $desktopPublic = sodium_crypto_kx_publickey($desktopKp);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'desktop-peer-2',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex($desktopPublic),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-14T10:00:00Z',
        'confirmed_at' => '2026-07-14T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-14T10:00:00Z',
        'updated_at' => '2026-07-14T10:00:00Z',
    ]);

    [$phoneNoiseSession, $desktopNoiseSession] = lanReceiveNoisePair(
        sodium_hex2bin($phone->x25519SecretKeyHex),
        sodium_hex2bin($phone->x25519PublicKeyHex),
        $desktopSecret,
        $desktopPublic,
    );

    $phoneSyncSession = lanReceiveBareSyncSession();
    $phoneSyncSession->authenticate($phoneNoiseSession, $userId, $phone->deviceId);

    // A genuinely non-JSON pushed frame — still a valid Noise ciphertext
    // (encrypted with the desktop's real send cipher), but not parseable as
    // a control message at all.
    $malformedFrame = $desktopNoiseSession->encrypt('not a control message');

    $connection = lanReceiveFakeConnection([WebsocketMessage::fromBinary($malformedFrame)]);

    /** @var LanSyncClient $client */
    $client = app(LanSyncClient::class);

    // Must not throw.
    $client->receiveGdkEpochWraps($connection, $phoneSyncSession, $userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([], 'a malformed frame must never append anything to the keyring');
});
