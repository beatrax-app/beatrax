<?php

declare(strict_types=1);

use Amp\Http\Server\Driver\Client as AmpDriverClient;
use Amp\Http\Server\Request as AmpRequest;
use Amp\Http\Server\Response as AmpResponse;
use Amp\Socket\InternetAddress;
use Amp\Websocket\WebsocketMessage;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Uri\Http as HttpUri;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Enums\SyncSessionStatus;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\PeerCatchUpExchanger;
use Modules\Sync\Internal\Transport\SyncWebSocketHandler;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Public\Services\GdkEpochDeliveryGateway;
use Modules\Sync\Tests\Support\DiallingSyncPeer;
use Modules\Sync\Tests\Support\RecordingLogger;
use Modules\Sync\Tests\Support\ScriptedPeerSocket;

uses(RefreshDatabase::class);

// Every phase of a session can end early, and each ending has to be told apart
// from the others by something a reader can see: a status on the session row, a
// notice the peer can read, or a confirmation cleared. handleClient() swallows
// the throw in all of them, so nothing above it reports which one happened.
/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md
 */
final class HangUpHousehold
{
    public readonly int $userId;

    public readonly string $secretKey;

    public readonly string $publicKey;

    public function __construct(public readonly string $deviceId = 'desktop-self')
    {
        $user = User::query()->create([
            'username' => 'hangup-'.bin2hex(random_bytes(5)),
            'password' => bcrypt('fixture'),
            'period_start_day' => 1,
            'default_currency_view' => 'eur_only',
        ]);

        $this->userId = (int) $user->id;

        $keypair = sodium_crypto_kx_keypair();
        $this->secretKey = sodium_crypto_kx_secretkey($keypair);
        $this->publicKey = sodium_crypto_kx_publickey($keypair);

        $this->register($this->deviceId, sodium_bin2hex($this->publicKey), isSelf: true);
    }

    public function register(string $deviceId, string $x25519Hex, bool $isSelf = false, ?string $confirmedAt = '2026-09-01T10:05:00Z'): string
    {
        $signing = sodium_crypto_sign_keypair();

        $this->db()->connection()->table('device_registry')->insert([
            'user_id' => $this->userId,
            'device_id' => $deviceId,
            'name' => $deviceId,
            'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($signing)),
            'x25519_public_key_hex' => $x25519Hex,
            'safety_number_words' => 'abandon ability able about above absent',
            'is_self' => $isSelf ? 1 : 0,
            'paired_at' => '2026-09-01T10:00:00Z',
            'confirmed_at' => $confirmedAt,
            'last_seen_at' => null,
            'created_at' => '2026-09-01T10:00:00Z',
            'updated_at' => '2026-09-01T10:00:00Z',
        ]);

        return sodium_bin2hex(sodium_crypto_sign_secretkey($signing));
    }

    public function handler(RecordingLogger $logger): SyncWebSocketHandler
    {
        return new SyncWebSocketHandler(
            registryService: app(DeviceRegistryService::class),
            signer: new DeviceKeySigner,
            framer: new TransportFramer,
            catchUp: app(PeerCatchUpExchanger::class),
            db: $this->db(),
            clock: app(Clock::class),
            logger: $logger,
            localStaticSecret: $this->secretKey,
            localStaticPublic: $this->publicKey,
            localDeviceId: $this->deviceId,
            userId: $this->userId,
        );
    }

    public function sessionRow(): ?object
    {
        return $this->db()->connection()->table('sync_sessions')->where('user_id', $this->userId)->first();
    }

    public function db(): DatabaseManager
    {
        return app(DatabaseManager::class);
    }
}

function hangUpRun(SyncWebSocketHandler $handler, ScriptedPeerSocket $socket): void
{
    $client = Mockery::mock(AmpDriverClient::class);
    $client->shouldReceive('getRemoteAddress')->andReturn(new InternetAddress('127.0.0.1', 51234));

    $handler->handleClient(
        $socket,
        new AmpRequest($client, 'GET', HttpUri::new('http://127.0.0.1:4100/'), [], ''),
        new AmpResponse,
    );
}

// The steps that get a peer past the handshake and through the epoch phase,
// so a test about a LATER phase does not have to restate the earlier ones.
/**
 * @return list<Closure(ScriptedPeerSocket): ?WebsocketMessage>
 */
function hangUpThroughEpochPhase(DiallingSyncPeer $peer): array
{
    return [
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
    ];
}

function hangUpSignedCreate(string $author, string $secretKeyHex, int $userId, int $hlcL, string $pk): array
{
    $signer = new DeviceKeySigner;
    $entries = [];

    foreach (['name' => 'Bakery '.$pk, 'normalized_name' => 'bakery '.$pk] as $field => $value) {
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

it('closes a connection whose peer never sends the first handshake message', function (): void {
    $house = new HangUpHousehold;
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(ScriptedPeerSocket::stalls());

    hangUpRun($house->handler($logger), $socket);

    expect($socket->wasClosed)->toBeTrue('a peer that connects and says nothing must not park a fiber')
        ->and($logger->said('Noise handshake failed'))->toBeTrue()
        ->and($house->sessionRow())->toBeNull('nothing was admitted, so nothing may claim a session row');
});

it('closes a connection whose first handshake message is not one', function (): void {
    $house = new HangUpHousehold;
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(ScriptedPeerSocket::sends(str_repeat("\x00", 96)));

    hangUpRun($house->handler($logger), $socket);

    expect($socket->wasClosed)->toBeTrue()
        ->and($logger->said('Noise handshake failed'))->toBeTrue()
        ->and($house->sessionRow())->toBeNull();
});

it('records a refusal and says nothing about a removal to a key it never admitted', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(ScriptedPeerSocket::sends($peer->handshakeMessage()));

    hangUpRun($house->handler($logger), $socket);

    $row = $house->sessionRow();

    expect($row?->status)->toBe(SyncSessionStatus::Failed->value, 'a refused peer must leave a refusal, not a closed session')
        ->and($row?->peer_device_id)->toBe('unknown')
        ->and($socket->wasClosed)->toBeTrue()
        ->and($socket->sentBinary)->toHaveCount(1, 'only the handshake reply — a device never admitted was never removed');
});

it('tells a device it did remove why the connection is going away', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex(), confirmedAt: null);

    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ScriptedPeerSocket::sends($peer->handshakeMessage()),
    );

    hangUpRun($house->handler($logger), $socket);

    expect($socket->sentBinary)->toHaveCount(2, 'the handshake reply, then the reason');

    $peer->adopt($socket->sent(0));

    expect($peer->decryptJson($socket->sent(1))['type'] ?? null)
        ->toBe(GdkEpochDeliveryGateway::MSG_PEER_REVOKED, 'a removed device told nothing keeps calling itself synced');

    expect($house->sessionRow()?->status)->toBe(SyncSessionStatus::Failed->value);
});

it('drops its own confirmation when the peer is the one doing the removing', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ScriptedPeerSocket::sends($peer->handshakeMessage()),
        static function (ScriptedPeerSocket $socket) use ($peer): WebsocketMessage {
            $peer->adopt($socket->sent(0));

            return WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => GdkEpochDeliveryGateway::MSG_PEER_REVOKED,
            ]));
        },
    );

    hangUpRun($house->handler($logger), $socket);

    expect(app(DeviceRegistryService::class)->isStillConfirmed($house->userId, 'phone-peer'))
        ->toBeFalse('a peer that no longer confirms this device must stop being offered as one it syncs with');

    expect($house->sessionRow()?->status)->toBe(SyncSessionStatus::Closed->value);
});

it('stops applying a peer\'s ops the moment the reader removes it mid-session', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $peerSecretHex = $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $framer = new TransportFramer;
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
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
            // The reader removes the device between catch-up and the next frame,
            // which is the whole window a connect-time snapshot cannot see.
            static function () use ($house, $peer, $framer, $peerSecretHex): WebsocketMessage {
                app(DeviceRegistryService::class)->forgetPeerConfirmation($house->userId, 'phone-peer');

                return WebsocketMessage::fromBinary($peer->encrypt(
                    $framer->encode(hangUpSignedCreate('phone-peer', $peerSecretHex, $house->userId, 88, '903'))
                ));
            },
        ],
    );

    hangUpRun($house->handler($logger), $socket);

    expect($logger->said('peer revoked mid-session'))->toBeTrue()
        ->and($house->db()->connection()->table('merchants')->where('id', 903)->exists())
        ->toBeFalse('an op arriving after the removal must not be replayed');
});

it('closes the socket of a peer that stalls in the middle of catch-up', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[ScriptedPeerSocket::stalls()],
    );

    hangUpRun($house->handler($logger), $socket);

    expect($socket->wasClosed)->toBeTrue('a peer that goes quiet mid-exchange must have its socket closed')
        ->and($logger->said('catch-up exchange failed'))->toBeTrue()
        ->and($house->sessionRow()?->status)->toBe(SyncSessionStatus::Closed->value);
});

it('finishes the exchange when the peer declares frames it then never sends', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildRequest($house->userId, 'phone-peer')
            )),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => PeerCatchUpExchanger::MSG_CATCH_UP_RESPONSE,
                'frame_count' => 5,
            ])),
            ScriptedPeerSocket::hangsUp(),
        ],
    );

    hangUpRun($house->handler($logger), $socket);

    $sent = array_map(static fn (string $frame): string => $peer->decrypt($frame), array_slice($socket->sentBinary, 1));
    $types = array_map(static function (string $plaintext): string {
        $decoded = json_decode($plaintext, true);

        return is_array($decoded) && is_string($decoded['type'] ?? null) ? $decoded['type'] : 'FRAME';
    }, $sent);

    expect($types)->toContain(PeerCatchUpExchanger::MSG_CATCH_UP_COMPLETE);
    expect($house->sessionRow()?->status)->toBe(SyncSessionStatus::Closed->value);
});

it('reads no frames at all from a peer that declares a negative count', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildRequest($house->userId, 'phone-peer')
            )),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => PeerCatchUpExchanger::MSG_CATCH_UP_RESPONSE,
                'frame_count' => -3,
            ])),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildComplete()
            )),
            ScriptedPeerSocket::hangsUp(),
        ],
    );

    hangUpRun($house->handler($logger), $socket);

    // Handshake, ack, push header, catch-up request, catch-up response,
    // catch-up complete, then the live read that ends the session. A frame
    // loop that ran even once would push this higher.
    expect($socket->receives)->toBe(7, 'a declared count below zero must buy the peer no reads at all');
});

it('treats an unreadable epoch control frame as an empty phase rather than a failure', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ScriptedPeerSocket::sends($peer->handshakeMessage()),
        static function (ScriptedPeerSocket $socket) use ($peer): WebsocketMessage {
            $peer->adopt($socket->sent(0));

            return WebsocketMessage::fromBinary($peer->encrypt('not json at all'));
        },
        static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encrypt('{"unterminated"')),
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
        ScriptedPeerSocket::hangsUp(),
    );

    hangUpRun($house->handler($logger), $socket);

    expect($logger->said('catch-up exchange failed'))
        ->toBeFalse('a garbled epoch header is an empty push, not a reason to drop a session that can still sync');

    expect($house->sessionRow()?->status)->toBe(SyncSessionStatus::Closed->value);
});

it('keeps a pending wrap when the peer answers the acknowledgement with something else', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $house->db()->connection()->table('relay_mailbox')->insert([
        'sender_did' => $house->deviceId,
        'recipient_did' => 'phone-peer',
        'blob' => json_encode(['type' => GdkEpochDeliveryGateway::MSG_EPOCH_WRAP, 'epoch_id' => 4], JSON_THROW_ON_ERROR),
        'created_at' => '2026-09-01T10:10:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-10-01T10:10:00Z',
    ]);

    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ScriptedPeerSocket::sends($peer->handshakeMessage()),
        // Where the acknowledgement belongs, the peer answers with its own push
        // header instead — an out-of-step build, not a hostile one.
        static function (ScriptedPeerSocket $socket) use ($peer): WebsocketMessage {
            $peer->adopt($socket->sent(0));

            return WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => GdkEpochDeliveryGateway::MSG_EPOCH_PUSH,
                'count' => 0,
            ]));
        },
        ScriptedPeerSocket::hangsUp(),
    );

    hangUpRun($house->handler($logger), $socket);

    expect(
        $house->db()->connection()->table('relay_mailbox')
            ->where('recipient_did', 'phone-peer')
            ->whereNull('delivered_at')
            ->count()
    )->toBe(1, 'nothing re-sends a fan-out, so an unacknowledged wrap must survive the connection that carried it');
});

it('hangs up rather than half-handshaking a peer that disconnects before msg1', function (): void {
    $house = new HangUpHousehold;
    $logger = new RecordingLogger;

    // Not a stall: the connection is simply gone, which reaches the handshake
    // as a null read rather than as a cancellation.
    $socket = ScriptedPeerSocket::running(ScriptedPeerSocket::hangsUp());

    hangUpRun($house->handler($logger), $socket);

    expect($socket->wasClosed)->toBeTrue()
        ->and($logger->said('Noise handshake failed'))->toBeTrue()
        ->and($house->sessionRow())->toBeNull();
});

it('does not turn a refusal into a throw when the refused peer has already gone', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex(), confirmedAt: null);

    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(ScriptedPeerSocket::sends($peer->handshakeMessage()));

    // The handshake reply lands, and the connection is gone by the time the
    // refusal is decided — which is the ordinary case, since a peer told it was
    // removed has every reason to have hung up already.
    $socket->refuseSendsAfter = 1;

    hangUpRun($house->handler($logger), $socket);

    expect($logger->said('could not tell the peer it was revoked'))
        ->toBeTrue('the notice is best-effort: the connection is closing either way')
        ->and($socket->wasClosed)->toBeTrue('and the close that follows it must still happen');

    expect($house->sessionRow()?->status)->toBe(SyncSessionStatus::Failed->value);
});

it('ends the exchange quietly when the peer hangs up after being asked for its history', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildRequest($house->userId, 'phone-peer')
            )),
            ScriptedPeerSocket::hangsUp(),
        ],
    );

    hangUpRun($house->handler($logger), $socket);

    expect($logger->said('catch-up exchange failed'))
        ->toBeFalse('a peer that closes the connection has not failed an exchange, it has ended one');

    expect($house->sessionRow()?->status)->toBe(SyncSessionStatus::Closed->value);
});

it('asks for no frames from a response that declares no count', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildRequest($house->userId, 'phone-peer')
            )),
            // An older build that never learned to declare one, and a hostile
            // one sending a string, are the same case: nothing was promised.
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => PeerCatchUpExchanger::MSG_CATCH_UP_RESPONSE,
                'frame_count' => 'lots',
            ])),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildComplete()
            )),
            ScriptedPeerSocket::hangsUp(),
        ],
    );

    hangUpRun($house->handler($logger), $socket);

    expect($socket->receives)->toBe(7, 'a count nothing can read is no count at all');
});

it('replays the history a peer sends during catch-up, not only what arrives live', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $peerSecretHex = $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $framer = new TransportFramer;
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildRequest($house->userId, 'phone-peer')
            )),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson([
                'type' => PeerCatchUpExchanger::MSG_CATCH_UP_RESPONSE,
                'frame_count' => 1,
            ])),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encrypt(
                $framer->encode(hangUpSignedCreate('phone-peer', $peerSecretHex, $house->userId, 66, '905'))
            )),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encryptJson(
                $catchUp->buildComplete()
            )),
            ScriptedPeerSocket::hangsUp(),
        ],
    );

    hangUpRun($house->handler($logger), $socket);

    expect($house->db()->connection()->table('merchants')->where('id', 905)->exists())
        ->toBeTrue('the frames a peer owes are the whole point of asking for them');

    expect($house->db()->connection()->table('sync_peer_catch_up_state')
        ->where('peer_device_id', 'phone-peer')->where('author_device_id', 'phone-peer')->exists())
        ->toBeTrue('and the cursor moves over what was accounted for');
});

it('ends a live stream on the first frame it cannot open', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $peerSecretHex = $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $framer = new TransportFramer;
    $logger = new RecordingLogger;

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
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
            // The Noise receive cipher is counter-based, so a frame it cannot
            // open leaves the stream unrecoverable rather than merely skipped.
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary(random_bytes(64)),
            static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encrypt(
                $framer->encode(hangUpSignedCreate('phone-peer', $peerSecretHex, $house->userId, 95, '906'))
            )),
        ],
    );

    hangUpRun($house->handler($logger), $socket);

    expect($logger->said('live stream error'))->toBeTrue()
        ->and($house->db()->connection()->table('merchants')->where('id', 906)->exists())
        ->toBeFalse('nothing behind an unopenable frame may be read as this peer\'s');

    expect($house->sessionRow()?->status)->toBe(SyncSessionStatus::Closed->value);
});

it('re-checks a peer\'s trust on a throttle rather than once per op', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $peerSecretHex = $house->register('phone-peer', $peer->publicKeyHex());

    $catchUp = app(PeerCatchUpExchanger::class);
    $framer = new TransportFramer;
    $logger = new RecordingLogger;

    $live = [];
    foreach ([[110, '910'], [120, '911'], [130, '912']] as [$hlc, $pk]) {
        $live[] = static fn (): WebsocketMessage => WebsocketMessage::fromBinary($peer->encrypt(
            $framer->encode(hangUpSignedCreate('phone-peer', $peerSecretHex, $house->userId, $hlc, $pk))
        ));
    }

    $socket = ScriptedPeerSocket::running(
        ...hangUpThroughEpochPhase($peer),
        ...[
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
        ],
        ...$live,
        ...[ScriptedPeerSocket::hangsUp()],
    );

    $connection = $house->db()->connection();
    $connection->flushQueryLog();
    $connection->enableQueryLog();

    hangUpRun($house->handler($logger), $socket);

    $trustChecks = array_filter(
        $connection->getQueryLog(),
        static fn (array $entry): bool => is_string($entry['query'] ?? null)
            && str_contains($entry['query'], '"device_registry"')
            && str_contains($entry['query'], '"confirmed_at" is not null')
            && str_contains($entry['query'], '"device_id" = ?'),
    );

    $connection->disableQueryLog();

    expect($house->db()->connection()->table('merchants')->whereIn('id', [910, 911, 912])->count())->toBe(3);
    expect($trustChecks)->toHaveCount(
        1,
        'the answer only changes when a reader removes a device, so asking per op buys nothing and costs a query each time',
    );
});

it('still acknowledges the epoch phase when the peer announces wraps it never sends', function (): void {
    $house = new HangUpHousehold;
    $peer = new DiallingSyncPeer($house->publicKey);
    $house->register('phone-peer', $peer->publicKeyHex());

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
            'count' => 3,
        ])),
        ScriptedPeerSocket::hangsUp(),
    );

    hangUpRun($house->handler($logger), $socket);

    $sent = array_map(static fn (string $frame): string => $peer->decrypt($frame), array_slice($socket->sentBinary, 1));
    /** @var array<string, mixed> $ack */
    $ack = json_decode($sent[1], true, 8, JSON_THROW_ON_ERROR);

    expect($ack['type'] ?? null)->toBe(GdkEpochDeliveryGateway::MSG_EPOCH_ACK)
        ->and($ack['count'] ?? null)->toBe(0, 'an announcement is not a delivery, and only a delivery may be retired');
});
