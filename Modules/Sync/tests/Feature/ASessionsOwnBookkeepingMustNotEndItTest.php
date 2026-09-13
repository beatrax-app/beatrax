<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\Client as AmpDriverClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
use Amp\Websocket\WebsocketMessage;
use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Uri\Http as HttpUri;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Enums\SyncSessionStatus;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Tests\Support\DialingSyncPeer;
use Modules\Sync\Tests\Support\RecordingLogger;
use Modules\Sync\Tests\Support\ScriptedPeerSocket;
use Modules\Sync\Tests\Support\StrandedSessionClock;

uses(RefreshDatabase::class);

// Four writes in this class exist only so a reader can date a session, and
// three of them say in a comment that losing the single SQLite writer must not
// end an exchange that is otherwise fine. The fourth runs at teardown and threw
// straight out of the responder, which the WebSocket layer reads as an internal
// server error on a session that had just finished cleanly.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
const BOOKKEEPING_NOW = '2026-09-05T12:00:00Z';

/**
 * @return array{0: int, 1: string, 2: string, 3: DialingSyncPeer, 4: string}
 */
function bookkeepingHousehold(): array
{
    $db = app(DatabaseManager::class);

    $user = User::query()->create([
        'username' => 'bookkeeping-'.bin2hex(random_bytes(5)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userId = (int) $user->id;

    $deskKx = sodium_crypto_kx_keypair();
    $deskSecret = sodium_crypto_kx_secretkey($deskKx);
    $deskPublic = sodium_crypto_kx_publickey($deskKx);

    $peer = new DialingSyncPeer($deskPublic);
    $peerSigning = sodium_crypto_sign_keypair();

    foreach ([
        ['desktop-self', sodium_bin2hex($deskPublic), 1, sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())],
        ['phone-peer', $peer->publicKeyHex(), 0, sodium_crypto_sign_publickey($peerSigning)],
    ] as [$deviceId, $x25519Hex, $isSelf, $ed25519]) {
        $db->connection()->table('device_registry')->insert([
            'user_id' => $userId,
            'device_id' => $deviceId,
            'name' => $deviceId,
            'ed25519_public_key_hex' => sodium_bin2hex($ed25519),
            'x25519_public_key_hex' => $x25519Hex,
            'safety_number_words' => 'abandon ability able about above absent',
            'is_self' => $isSelf,
            'paired_at' => '2026-09-01T10:00:00Z',
            'confirmed_at' => '2026-09-01T10:05:00Z',
            'last_seen_at' => null,
            'created_at' => '2026-09-01T10:00:00Z',
            'updated_at' => '2026-09-01T10:00:00Z',
        ]);
    }

    return [$userId, $deskSecret, $deskPublic, $peer, sodium_bin2hex(sodium_crypto_sign_secretkey($peerSigning))];
}

function bookkeepingFreezeClock(): StrandedSessionClock
{
    $clock = new StrandedSessionClock(CarbonImmutable::parse(BOOKKEEPING_NOW));
    app()->instance(Clock::class, $clock);

    return $clock;
}

// SQLite has no way to hold a writer open inside the suite's own transaction,
// so the refusal is spelled as a trigger: the write that loses the race and the
// write a trigger aborts reach the caller as the same throw.
function bookkeepingRefuseWritesTo(string $table, string $event): void
{
    app(DatabaseManager::class)->connection()->statement(sprintf(
        'CREATE TRIGGER refuse_%1$s_%2$s BEFORE %2$s ON %1$s BEGIN SELECT RAISE(ABORT, \'database is locked\'); END',
        $table,
        $event,
    ));
}

/** @param array<string, string> $deviceKeys */
function bookkeepingSession(RecordingLogger $logger, array $deviceKeys = [], ?int $userId = null): SyncSession
{
    $db = app(DatabaseManager::class);

    return new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db, deviceKeys: $deviceKeys, deviceKeysUserId: $userId),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
        logger: $logger,
    );
}

function bookkeepingHandshake(DialingSyncPeer $peer, string $deskSecret, string $deskPublic): NoiseSession
{
    $responder = NoiseHandshakeState::initIkResponder($deskSecret, $deskPublic);
    $responder->readMessage($peer->handshakeMessage());
    $peer->adopt($responder->writeMessage(''));

    [$send, $receive, $peerStatic] = $responder->split();

    return new NoiseSession($send, $receive, $peerStatic);
}

it('closes without throwing when the write that records the close is refused', function (): void {
    bookkeepingFreezeClock();
    [$userId, $deskSecret, $deskPublic, $peer] = bookkeepingHousehold();

    $logger = new RecordingLogger;
    $session = bookkeepingSession($logger);

    expect($session->authenticate(bookkeepingHandshake($peer, $deskSecret, $deskPublic), $userId, 'desktop-self'))->toBeTrue();

    bookkeepingRefuseWritesTo('sync_sessions', 'UPDATE');

    $session->close();

    expect($session->status())->toBe(SyncSessionStatus::Closed, 'the object is closed whatever the row says')
        ->and($logger->said('session close stamp skipped'))->toBeTrue('a skipped stamp is reported, not swallowed in silence');
});

it('lets the responder finish a session whose closing stamp cannot be written', function (): void {
    bookkeepingFreezeClock();
    [$userId, $deskSecret, $deskPublic, $peer] = bookkeepingHousehold();

    $catchUp = app(PeerCatchUpExchanger::class);
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ScriptedPeerSocket::sends($peer->handshakeMessage()),
        static function (ScriptedPeerSocket $socket) use ($peer): WebsocketMessage {
            $peer->adopt($socket->sent(0));

            return WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => GdkEpochDeliveryGateway::MSG_EPOCH_ACK, 'count' => 0,
            ]));
        },
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
            'type' => GdkEpochDeliveryGateway::MSG_EPOCH_PUSH, 'count' => 0,
        ])),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
            $catchUp->buildRequest($userId, 'phone-peer')
        )),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
            'type' => PeerCatchUpExchanger::MSG_CATCH_UP_RESPONSE, 'frame_count' => 0,
        ])),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson($catchUp->buildComplete())),
        // The writer was free at connect and is gone by the time the session
        // ends, which is the only window this write ever runs in.
        static function (): ?WebsocketMessage {
            bookkeepingRefuseWritesTo('sync_sessions', 'UPDATE');

            return null;
        },
    );

    $handler = new SyncWebSocketHandler(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        framer: new TransportFramer,
        catchUp: $catchUp,
        db: app(DatabaseManager::class),
        clock: app(Clock::class),
        logger: $logger,
        localStaticSecret: $deskSecret,
        localStaticPublic: $deskPublic,
        localDeviceId: 'desktop-self',
        userId: $userId,
    );

    $driverClient = Mockery::mock(AmpDriverClient::class);
    $driverClient->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('127.0.0.1', 51234));

    // The contract the WebSocket layer holds this method to: a throw is read as
    // an internal server error and answered with an abnormal close, on a
    // session where every phase had already succeeded.
    $handler->handleClient(
        $socket,
        new AmpRequest($driverClient, 'GET', HttpUri::new('http://127.0.0.1:4100/'), [], ''),
        new AmpResponse,
    );

    expect($logger->said('peer authenticated'))->toBeTrue()
        ->and($logger->said('session close stamp skipped'))->toBeTrue();
});

it('admits a peer whose last-seen stamp loses the race for the writer', function (): void {
    bookkeepingFreezeClock();
    [$userId, $deskSecret, $deskPublic, $peer] = bookkeepingHousehold();

    bookkeepingRefuseWritesTo('device_registry', 'UPDATE');

    $logger = new RecordingLogger;
    $session = bookkeepingSession($logger);

    expect($session->authenticate(bookkeepingHandshake($peer, $deskSecret, $deskPublic), $userId, 'desktop-self'))
        ->toBeTrue('a lost race over a timestamp must not close a connection that is about to catch up');

    expect($logger->said('last_seen_at write skipped'))->toBeTrue()
        ->and(app(DatabaseManager::class)->connection()->table('sync_sessions')->where('user_id', $userId)->value('status'))
        ->toBe(SyncSessionStatus::Active->value);
});

it('carries a peer\'s ops even when no session row could be written for them', function (): void {
    $clock = bookkeepingFreezeClock();
    [$userId, $deskSecret, $deskPublic, $peer, $peerSecretHex] = bookkeepingHousehold();

    bookkeepingRefuseWritesTo('sync_sessions', 'INSERT');

    $logger = new RecordingLogger;
    $session = bookkeepingSession($logger, app(DeviceRegistryService::class)->signatureVerificationKeys($userId), $userId);

    expect($session->authenticate(bookkeepingHandshake($peer, $deskSecret, $deskPublic), $userId, 'desktop-self'))->toBeTrue();
    expect($logger->said('session-row write skipped'))->toBeTrue();

    $signer = new DeviceKeySigner;
    $entries = [];
    $hlcL = 51;

    foreach (['name' => 'Bakery', 'normalized_name' => 'bakery'] as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'merchants', pk: '904', field: $field,
            value: json_encode($value, JSON_THROW_ON_ERROR),
            hlcL: $hlcL, hlcC: 0, deviceId: 'phone-peer',
            opType: OpType::CreateRow, signature: $signature, userId: $userId,
        );
        $entries[] = $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($peerSecretHex)));
        $hlcL++;
    }

    // Past the stamp interval, so the stamp would be attempted if there were
    // any row at all to stamp.
    $clock->travelTo(CarbonImmutable::parse('2026-09-05T12:30:00Z'));

    $session->receiveOps(
        $peer->encrypt((new TransportFramer)->encode($entries)),
        $userId,
        app(DeviceRegistryService::class)->signatureVerificationKeys($userId),
    );

    expect(app(DatabaseManager::class)->connection()->table('merchants')->where('id', 904)->exists())
        ->toBeTrue('bookkeeping the reader never sees must not stand between a peer and its history');

    $session->close();
});

it('goes on carrying ops when the stamp that dates the session is refused', function (): void {
    $clock = bookkeepingFreezeClock();
    [$userId, $deskSecret, $deskPublic, $peer, $peerSecretHex] = bookkeepingHousehold();

    $logger = new RecordingLogger;
    $session = bookkeepingSession($logger, app(DeviceRegistryService::class)->signatureVerificationKeys($userId), $userId);

    expect($session->authenticate(bookkeepingHandshake($peer, $deskSecret, $deskPublic), $userId, 'desktop-self'))->toBeTrue();

    $stampedAtHandshake = app(DatabaseManager::class)->connection()
        ->table('sync_sessions')->where('user_id', $userId)->value('last_seen_at');

    bookkeepingRefuseWritesTo('sync_sessions', 'UPDATE');

    // Past the interval, so the stamp is attempted rather than throttled away.
    $clock->travelTo(CarbonImmutable::parse('2026-09-05T12:30:00Z'));

    $signer = new DeviceKeySigner;
    $entries = [];
    $hlcL = 61;

    foreach (['name' => 'Bakery', 'normalized_name' => 'bakery'] as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'merchants', pk: '907', field: $field,
            value: json_encode($value, JSON_THROW_ON_ERROR),
            hlcL: $hlcL, hlcC: 0, deviceId: 'phone-peer',
            opType: OpType::CreateRow, signature: $signature, userId: $userId,
        );
        $entries[] = $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($peerSecretHex)));
        $hlcL++;
    }

    $session->receiveOps(
        $peer->encrypt((new TransportFramer)->encode($entries)),
        $userId,
        app(DeviceRegistryService::class)->signatureVerificationKeys($userId),
    );

    expect(app(DatabaseManager::class)->connection()->table('merchants')->where('id', 907)->exists())
        ->toBeTrue('a stamp nobody could write must not end a session that is carrying ops perfectly well');

    expect($logger->said('session last-seen stamp skipped'))->toBeTrue()
        ->and(app(DatabaseManager::class)->connection()->table('sync_sessions')->where('user_id', $userId)->value('last_seen_at'))
        ->toBe($stampedAtHandshake, 'and the row honestly still reads as last dated at the handshake');
});
