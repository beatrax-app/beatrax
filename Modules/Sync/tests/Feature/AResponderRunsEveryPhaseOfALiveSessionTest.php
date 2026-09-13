<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\Client as AmpDriverClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
use Amp\Websocket\WebsocketMessage;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Uri\Http as HttpUri;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapRecipient;
use Modules\Sync\Internal\Enums\SyncSessionStatus;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Tests\Support\DialingSyncPeer;
use Modules\Sync\Tests\Support\RecordingLogger;
use Modules\Sync\Tests\Support\ResponderHousehold;
use Modules\Sync\Tests\Support\ScriptedPeerSocket;

uses(RefreshDatabase::class);

// handleClient() is the only method that runs the phases in order, and every
// test that reached this class before called one phase at a time by hand. The
// order between them is the contract: keys before the data they decrypt, the
// catch-up request read before the answer is built, the live loop entered only
// once both have finished.

// handleClient() never reads either of these, but it cannot be called without
// them: the interface amphp calls this through hands over the upgrade request
// and the response that accepted it.
function responderUpgrade(): array
{
    $client = Mockery::mock(AmpDriverClient::class);
    $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('127.0.0.1', 51234));

    return [
        new AmpRequest($client, 'GET', HttpUri::new('http://127.0.0.1:4100/'), [], ''),
        new AmpResponse,
    ];
}

function responderRun(SyncWebSocketHandler $handler, ScriptedPeerSocket $socket): void
{
    [$request, $response] = responderUpgrade();

    $handler->handleClient($socket, $request, $response);
}

// A whole create group signed by the peer, the way a live frame carries one.
// One field of it projects nothing and quarantines as an incomplete create,
// which would prove only that the frame was decoded.
/**
 * @return list<OpLogEntry>
 */
function responderSignedCreate(string $author, string $secretKeyHex, int $userId, int $hlcL, string $pk): array
{
    $signer = new DeviceKeySigner;
    $entries = [];

    foreach (['name' => 'Live bakery', 'normalized_name' => 'live bakery'] as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'merchants',
            pk: $pk,
            field: $field,
            value: json_encode($value, JSON_THROW_ON_ERROR),
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: $author,
            opType: OpType::CreateRow,
            signature: $signature,
            userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), sodium_hex2bin($secretKeyHex)));
        $hlcL++;
    }

    return $entries;
}

it('runs the handshake, the epoch phase, catch-up and the live stream in one connection', function (): void {
    $house = new ResponderHousehold;
    $peer = new DialingSyncPeer($house->publicKey);

    $peerSigning = sodium_crypto_sign_keypair();
    $peerSecretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($peerSigning));
    $house->register('phone-peer', $peer->publicKeyHex(), sodium_bin2hex(sodium_crypto_sign_publickey($peerSigning)));

    // One entry this desktop owes the phone, so the delta loop really streams a
    // frame rather than the count being zero on both legs.
    $house->owe($house->deviceId, 40, '901');

    $catchUp = app(PeerCatchUpExchanger::class);
    $framer = new TransportFramer;
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ScriptedPeerSocket::sends($peer->handshakeMessage()),
        static function (ScriptedPeerSocket $socket) use ($peer): WebsocketMessage {
            $peer->adopt($socket->sent(0));

            return WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => GdkEpochDeliveryGateway::MSG_EPOCH_ACK,
                'count' => 0,
            ]));
        },
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
            'type' => GdkEpochDeliveryGateway::MSG_EPOCH_PUSH,
            'count' => 0,
        ])),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
            $catchUp->buildRequest($house->userId, 'phone-peer')
        )),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
            'type' => PeerCatchUpExchanger::MSG_CATCH_UP_RESPONSE,
            'frame_count' => 0,
        ])),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
            $catchUp->buildComplete()
        )),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encrypt(
            $framer->encode(responderSignedCreate('phone-peer', $peerSecretHex, $house->userId, 77, '902'))
        )),
        ScriptedPeerSocket::hangsUp(),
    );

    responderRun($house->handler($logger), $socket);

    expect($logger->said('peer authenticated'))->toBeTrue('the session must have got past the admission gate');

    // Read in send order: the Noise receive cipher is sequential, so the peer
    // can only read this log as one stream.
    $sent = array_map(static fn (string $frame): string => $peer->decrypt($frame), array_slice($socket->sentBinary, 1));

    $types = array_map(static function (string $plaintext): string {
        $decoded = json_decode($plaintext, true);

        return is_array($decoded) && is_string($decoded['type'] ?? null) ? $decoded['type'] : 'FRAME';
    }, $sent);

    expect($types)->toBe([
        GdkEpochDeliveryGateway::MSG_EPOCH_PUSH,
        GdkEpochDeliveryGateway::MSG_EPOCH_ACK,
        PeerCatchUpExchanger::MSG_CATCH_UP_RESPONSE,
        'FRAME',
        PeerCatchUpExchanger::MSG_CATCH_UP_REQUEST,
        PeerCatchUpExchanger::MSG_CATCH_UP_COMPLETE,
    ], 'the epoch phase must finish before catch-up and the owed frame must follow the response that declared it');

    expect($framer->decode($sent[3]))->toHaveCount(1, 'the owed entry must cross the wire');

    // The live frame was applied, which is the only proof the loop was entered
    // rather than the connection ending with catch-up.
    expect($house->db()->connection()->table('merchants')->where('id', 902)->exists())
        ->toBeTrue('an op received on the live stream must be replayed');

    $row = $house->db()->connection()->table('sync_sessions')->where('user_id', $house->userId)->first();

    expect($row?->peer_device_id)->toBe('phone-peer')
        ->and($row?->status)->toBe(SyncSessionStatus::Closed->value, 'a session the responder ran to the end must not read as open forever');

    expect($house->db()->connection()->table('device_registry')->where('device_id', 'phone-peer')->value('last_seen_at'))
        ->not->toBeNull('authenticating a peer stamps when it was last heard from');
});

it('keeps a pushed epoch wrap it cannot open rather than acknowledging it into nothing', function (): void {
    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    $user = User::query()->create([
        'username' => 'pushed-wrap-'.bin2hex(random_bytes(5)),
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    $userId = (int) $user->id;
    $db = app(DatabaseManager::class);

    $self = app(DeviceIdentityService::class)->generateAndPersist($userId, $session);

    $deskKx = sodium_crypto_kx_keypair();
    $deskSecret = sodium_crypto_kx_secretkey($deskKx);
    $deskPublic = sodium_crypto_kx_publickey($deskKx);
    $peer = new DialingSyncPeer($deskPublic);

    $senderSigning = sodium_crypto_sign_keypair();
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'phone-peer',
        'name' => 'phone-peer',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($senderSigning)),
        'x25519_public_key_hex' => $peer->publicKeyHex(),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-09-01T10:00:00Z',
        'confirmed_at' => '2026-09-01T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-09-01T10:00:00Z',
        'updated_at' => '2026-09-01T10:00:00Z',
    ]);

    $wrap = json_encode(app(GdkRotationService::class)->buildGdkEpochWrap(
        4242,
        random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        'phone-peer',
        sodium_bin2hex(sodium_crypto_sign_secretkey($senderSigning)),
    ), JSON_THROW_ON_ERROR);

    // The listener daemon holds no app-lock key, so the sealed box cannot be
    // opened in this process — and the sender is about to forget its copy.
    app(AppLockKeyService::class)->withhold($session);

    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ScriptedPeerSocket::sends($peer->handshakeMessage()),
        static function (ScriptedPeerSocket $socket) use ($peer): WebsocketMessage {
            $peer->adopt($socket->sent(0));

            return WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => GdkEpochDeliveryGateway::MSG_EPOCH_ACK,
                'count' => 0,
            ]));
        },
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
            'type' => GdkEpochDeliveryGateway::MSG_EPOCH_PUSH,
            'count' => 1,
        ])),
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encrypt($wrap)),
        ScriptedPeerSocket::hangsUp(),
    );

    $handler = new SyncWebSocketHandler(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        framer: new TransportFramer,
        catchUp: app(PeerCatchUpExchanger::class),
        db: $db,
        clock: app(Clock::class),
        logger: $logger,
        localStaticSecret: $deskSecret,
        localStaticPublic: $deskPublic,
        localDeviceId: $self->deviceId,
        userId: $userId,
    );

    responderRun($handler, $socket);

    expect(
        $db->connection()->table('relay_mailbox')
            ->where('recipient_did', $self->deviceId)
            ->whereNull('delivered_at')
            ->count()
    )->toBe(1, 'a key this process could not open has one place left to live, and it is not nowhere');

    $sent = array_map(static fn (string $frame): string => $peer->decrypt($frame), array_slice($socket->sentBinary, 1));
    /** @var array<string, mixed> $ack */
    $ack = json_decode($sent[1], true, 8, JSON_THROW_ON_ERROR);

    expect($ack['type'] ?? null)->toBe(GdkEpochDeliveryGateway::MSG_EPOCH_ACK)
        ->and($ack['count'] ?? null)->toBe(1, 'the sender may retire its copy only because this side kept one');
});
