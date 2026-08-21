<?php

declare(strict_types=1);

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Http\Client\Response;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\UnixAddress;
use Amp\TimeoutException;
use Amp\Websocket\Client\WebsocketConnection;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Mobile\Internal\Exceptions\LanSyncException;
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

// A real loopback WebSocket dial stays manual-only verification, so these
// drive the same in-memory Noise handshake, SyncSession::authenticate() and
// receive step through a fake connection instead.

// Only receive() is exercised; every other method throws if called. Messages
// are served in order, then receive() returns null — a clean disconnect.
function lanReceiveFakeConnection(array $messages): WebsocketConnection
{
    return new class($messages) implements IteratorAggregate, WebsocketConnection
    {
        /** @var list<string> */
        public array $sentBinary = [];

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

        // The receive step now acknowledges what it accounted for, so a
        // connection that refused to send would stall the phase it is here
        // to exercise. Recorded rather than discarded.
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

// The header opening the epoch phase, sealed with the desktop's own send
// cipher. The phase runs BEFORE catch-up, so its length is announced rather
// than inferred from a read timeout, which would eat the request behind it.
function lanReceivePushHeader(NoiseSession $desktopNoiseSession, int $count): WebsocketMessage
{
    return WebsocketMessage::fromBinary($desktopNoiseSession->encrypt(json_encode(
        ['type' => 'GDK_EPOCH_PUSH', 'count' => $count],
        JSON_THROW_ON_ERROR,
    )));
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
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([]);

    // Two real keypairs: X25519 so authenticate() genuinely admits the peer,
    // Ed25519 because the receive gateway checks the wrap's signature
    // against the confirmed device's recorded key.
    $desktopKp = sodium_crypto_kx_keypair();
    $desktopSecret = sodium_crypto_kx_secretkey($desktopKp);
    $desktopPublic = sodium_crypto_kx_publickey($desktopKp);
    $desktopSigKp = sodium_crypto_sign_keypair();
    $desktopEdSecretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($desktopSigKp));

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'desktop-peer',
        'name' => 'Desktop',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($desktopSigKp)),
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

    // Encrypted with the desktop's OWN send cipher: Noise ciphers are
    // bidirectional, so the phone decrypts with its matching recv cipher.
    $rawEpochKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $recipientPub = sodium_hex2bin($phone->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(1, $rawEpochKey, $recipientPub, $phone->deviceId, 'desktop-peer', $desktopEdSecretHex);

    // Sealed in wire order — Noise cipher states are counter-based.
    $header = lanReceivePushHeader($desktopNoiseSession, 1);
    $pushedFrame = $desktopNoiseSession->encrypt(json_encode($wrap, JSON_THROW_ON_ERROR));

    $connection = lanReceiveFakeConnection([$header, WebsocketMessage::fromBinary($pushedFrame)]);

    /** @var LanSyncClient $client */
    $client = app(LanSyncClient::class);
    $client->receiveGdkEpochWraps($connection, $phoneSyncSession, $phone, 'desktop-peer', $session);

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
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

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

    // A valid Noise ciphertext that is not parseable as a control message.
    $header = lanReceivePushHeader($desktopNoiseSession, 1);
    $malformedFrame = $desktopNoiseSession->encrypt('not a control message');

    $connection = lanReceiveFakeConnection([$header, WebsocketMessage::fromBinary($malformedFrame)]);

    /** @var LanSyncClient $client */
    $client = app(LanSyncClient::class);

    $client->receiveGdkEpochWraps($connection, $phoneSyncSession, $phone, 'desktop-peer', $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([], 'a malformed frame must never append anything to the keyring');
});

// sendBinary() is a no-op so performHandshake's msg1 send does not blow up.
// receive() replays a script: a message is returned as-is, '__NULL__' is a
// clean disconnect, '__TIMEOUT__' throws Amp\TimeoutException.
function lanScriptedFakeConnection(array $script): WebsocketConnection
{
    return new class($script) implements IteratorAggregate, WebsocketConnection
    {
        private int $cursor = 0;

        public function __construct(private array $script) {}

        public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
        {
            if ($this->cursor >= count($this->script)) {
                return null;
            }

            $item = $this->script[$this->cursor++];

            if ($item === '__TIMEOUT__') {
                throw new TimeoutException('scripted read timeout');
            }

            if ($item === '__NULL__') {
                return null;
            }

            return $item;
        }

        public function getHandshakeResponse(): Response
        {
            throw new LogicException('not used in this test');
        }

        public function getId(): int
        {
            return 2;
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

        public function sendText(string $data): void {}

        public function sendBinary(string $data): void {}

        public function streamText(ReadableStream $stream): void {}

        public function streamBinary(ReadableStream $stream): void {}

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

it('throws LanSyncException when the peer disconnects before sending the Noise msg2 handshake frame', function (): void {
    $user = lanReceiveUser('lan-handshake-disconnect');
    $userId = (int) $user->id;
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    // A real, well-formed desktop static X25519 public key — the handshake
    // gets far enough to send msg1, then the peer vanishes (receive() ->
    // null) before msg2, which is the premature-disconnect throw.
    $desktopPublicHex = sodium_bin2hex(sodium_crypto_kx_publickey(sodium_crypto_kx_keypair()));

    /** @var LanSyncClient $client */
    $client = app(LanSyncClient::class);

    $performHandshake = new ReflectionMethod($client, 'performHandshake');
    $connection = lanScriptedFakeConnection(['__NULL__']);

    expect(fn () => $performHandshake->invoke($client, $connection, $phone, $desktopPublicHex))
        ->toThrow(LanSyncException::class, 'peer disconnected before sending Noise msg2');
});

it('treats a timed-out GDK phase header as a retryable stall and appends nothing', function (): void {
    $user = lanReceiveUser('lan-receive-timeout');
    $userId = (int) $user->id;
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

    // The header opens the phase, so a timeout waiting for it is a stalled
    // peer, not "no more wraps". syncOnce() maps the throw onto its
    // retryable return; continuing into catch-up would misread the stream.
    $phoneSyncSession = lanReceiveBareSyncSession();
    $connection = lanScriptedFakeConnection(['__TIMEOUT__']);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $phone = $identityService->generateAndPersist($userId, $session);

    /** @var LanSyncClient $client */
    $client = app(LanSyncClient::class);

    expect(fn () => $client->receiveGdkEpochWraps($connection, $phoneSyncSession, $phone, 'desktop-peer', $session))
        ->toThrow(TimeoutException::class);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([], 'a stalled phase must never append anything to the keyring');
});

it('ends the GDK receive loop on a well-formed but non-wrap control frame', function (): void {
    $user = lanReceiveUser('lan-receive-nonwrap');
    $userId = (int) $user->id;
    test()->actingAs($user);

    @unlink(UserDataPathService::appPath('sync/gdk/'.$userId.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat('k', 32));

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
        'device_id' => 'desktop-peer-nonwrap',
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

    // A perfectly valid control message that parseControlMessage() accepts
    // (JSON object with a string "type"), but whose type is NOT
    // GDK_EPOCH_WRAP — the desktop's fixed push step is over, so the loop
    // ends here without routing anything into the delivery gateway.
    $header = lanReceivePushHeader($desktopNoiseSession, 1);
    $nonWrapFrame = $desktopNoiseSession->encrypt(json_encode(['type' => 'CATCH_UP_COMPLETE'], JSON_THROW_ON_ERROR));

    $connection = lanReceiveFakeConnection([$header, WebsocketMessage::fromBinary($nonWrapFrame)]);

    /** @var LanSyncClient $client */
    $client = app(LanSyncClient::class);
    $client->receiveGdkEpochWraps($connection, $phoneSyncSession, $phone, 'desktop-peer', $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    expect($keyring->loadKeyring($userId, $session)->epochs())->toBe([], 'a non-wrap control frame must never append anything to the keyring');
});

it('LanSyncException::peerFailedConfirmedDeviceGate carries the confirmed-device-gate rejection message', function (): void {
    // syncOnce() throws this the moment a peer completes the Noise handshake
    // yet fails SyncSession::authenticate() (a security-relevant rejection,
    // never a retryable transient). The live-dial throw site is Manual-Only,
    // so the factory contract is pinned here directly.
    $exception = LanSyncException::peerFailedConfirmedDeviceGate();

    expect($exception)->toBeInstanceOf(LanSyncException::class)
        ->and($exception->getMessage())->toContain('confirmed-device auth gate');
});
