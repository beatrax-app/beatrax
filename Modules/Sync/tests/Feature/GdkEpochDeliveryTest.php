<?php

declare(strict_types=1);

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\Socket\UnixAddress;
use Amp\Websocket\WebsocketClient;
use Amp\Websocket\WebsocketCloseInfo;
use Amp\Websocket\WebsocketCount;
use Amp\Websocket\WebsocketMessage;
use Amp\Websocket\WebsocketTimestamp;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkEpoch;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\OpLogFieldCrypto;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
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

/**
 * @link ../../../../.docs/features/sync/gdk-epoch-wrap-delivery.md
 */
function deliveryUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return array{0: string, 1: string} [device_id, ed25519PublicKeyHex]
 */
function deliveryRegistryRow(DatabaseManager $db, int $userId, string $deviceId, string $x25519PublicKeyHex, bool $isSelf, bool $confirmed = true): void
{
    $sigKp = sodium_crypto_sign_keypair();

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => $x25519PublicKeyHex,
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => $isSelf ? 1 : 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => $confirmed ? '2026-07-09T10:05:00Z' : null,
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);
}

/**
 * @return array{0: string, 1: string} [senderDeviceId, ed25519SecretKeyHex]
 */
function deliverySender(DatabaseManager $db, int $userId, string $deviceId = 'gdk-sender-a'): array
{
    $sigKp = sodium_crypto_sign_keypair();
    $boxKp = sodium_crypto_box_keypair();

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $deviceId,
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($sigKp)),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey($boxKp)),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    return [$deviceId, sodium_bin2hex(sodium_crypto_sign_secretkey($sigKp))];
}

// The peer's half of the epoch phase: it acknowledges what it accounted for,
// then announces its own push. Both legs run every connect, so a fake client
// that served neither would stall the handler on a read.
/**
 * @return list<WebsocketMessage>
 */
function deliveryPeerReplies(NoiseSession $peer, int $acknowledged, int $rounds = 1): array
{
    $frames = [];

    for ($i = 0; $i < $rounds; $i++) {
        $frames[] = WebsocketMessage::fromBinary($peer->encrypt(json_encode([
            'type' => SyncWebSocketHandler::MSG_GDK_EPOCH_ACK,
            'count' => $i === 0 ? $acknowledged : 0,
        ], JSON_THROW_ON_ERROR)));

        $frames[] = WebsocketMessage::fromBinary($peer->encrypt(json_encode([
            'type' => SyncWebSocketHandler::MSG_GDK_EPOCH_PUSH,
            'count' => 0,
        ], JSON_THROW_ON_ERROR)));
    }

    return $frames;
}

/**
 * @param  list<WebsocketMessage>  $inbound
 */
function deliveryFakeClient(array $inbound = []): WebsocketClient
{
    return new class($inbound) implements IteratorAggregate, WebsocketClient
    {
        /** @var list<string> */
        public array $sentBinary = [];

        private int $cursor = 0;

        /** @param list<WebsocketMessage> $inbound */
        public function __construct(private array $inbound) {}

        public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
        {
            return $this->inbound[$this->cursor++] ?? null;
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

function deliverySyncWebSocketHandler(int $userId, string $localDeviceId, string $localSecretHex, string $localPublicHex): SyncWebSocketHandler
{
    return new SyncWebSocketHandler(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        framer: new TransportFramer,
        catchUp: app(PeerCatchUpExchanger::class),
        db: app(DatabaseManager::class),
        clock: app(Clock::class),
        logger: app(LoggerInterface::class),
        localStaticSecret: $localSecretHex,
        localStaticPublic: $localPublicHex,
        localDeviceId: $localDeviceId,
        userId: $userId,
    );
}

function deliveryBareSyncSession(): SyncSession
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

it('opens a GDK_EPOCH_WRAP addressed to this device and appends the epoch to the local keyring', function (): void {
    $user = deliveryUser('delivery-open-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    // Only a confirmed peer's signature clears the sender-authenticity gate.
    [$senderId, $senderSecretHex] = deliverySender(app(DatabaseManager::class), (int) $user->id);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(7, $rawGdkKey, $recipientPub, $deviceB->deviceId, $senderId, $senderSecretHex);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(7))->toBe(sodium_bin2hex($rawGdkKey));

    /** @var OpLogFieldCrypto $fieldCrypto */
    $fieldCrypto = app(OpLogFieldCrypto::class);
    $ad = 'transactions:1:description:7';
    $stored = $fieldCrypto->encrypt('super secret merchant', $rawGdkKey, $ad);
    $decrypted = $fieldCrypto->decrypt($stored, sodium_hex2bin((string) $loaded->keyFor(7)), $ad);

    expect($decrypted)->toBe('super secret merchant');
});

it('rejects a GDK_EPOCH_WRAP addressed to a foreign device and does not append', function (): void {
    $user = deliveryUser('delivery-foreign-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    // The sender is valid: the rejection is at the recipient-identity gate,
    // which runs before the sender-authenticity gate.
    [$senderId, $senderSecretHex] = deliverySender(app(DatabaseManager::class), (int) $user->id);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $foreignPub = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
    $wrap = $rotation->buildGdkEpochWrap(9, $rawGdkKey, $foreignPub, 'a-foreign-device-id', $senderId, $senderSecretHex);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(9))->toBeNull();
});

it('rejects a tampered GDK_EPOCH_WRAP wrapped_key_b64 and does not append', function (): void {
    $user = deliveryUser('delivery-tampered-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    // A genuinely confirmed sender, so the tamper below is caught by the
    // signature check and not by an unrelated unconfirmed-sender rejection.
    [$senderId, $senderSecretHex] = deliverySender(app(DatabaseManager::class), (int) $user->id);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(11, $rawGdkKey, $recipientPub, $deviceB->deviceId, $senderId, $senderSecretHex);

    $sealed = base64_decode((string) $wrap['wrapped_key_b64'], true);
    expect($sealed)->not->toBeFalse();
    $lastByte = substr((string) $sealed, -1);
    $tampered = substr((string) $sealed, 0, -1).chr(ord($lastByte) ^ 0xFF);
    $wrap['wrapped_key_b64'] = base64_encode($tampered);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(11))->toBeNull();
});

it('rejects a malformed control message without any sodium call', function (): void {
    $user = deliveryUser('delivery-malformed-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);

    // The keyring file is keyed by user id alone and survives RefreshDatabase,
    // so a reused rowid can inherit an earlier test's epochs — assert on a
    // delta, not on an assumed-empty keyring.
    $before = $keyring->loadKeyring((int) $user->id, $session)->epochs();

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);

    // Missing epoch_id/wrapped_key_b64/recipient_device_id entirely.
    $handler->handle(json_encode(['type' => 'GDK_EPOCH_WRAP'], JSON_THROW_ON_ERROR), (int) $user->id, $session);

    $after = $keyring->loadKeyring((int) $user->id, $session)->epochs();

    expect($after)->toHaveCount(count($before), 'a malformed message must never append an epoch');
});

it('is idempotent — re-handling an already-present epoch does not duplicate or downgrade current_epoch', function (): void {
    $user = deliveryUser('delivery-idempotent-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $selfMinted = $keyring->generateAndPersist((int) $user->id, $session); // epoch 1

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    [$senderId, $senderSecretHex] = deliverySender(app(DatabaseManager::class), (int) $user->id);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(2, $rawGdkKey, $recipientPub, $deviceB->deviceId, $senderId, $senderSecretHex);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $json = json_encode($wrap, JSON_THROW_ON_ERROR);

    $handler->handle($json, (int) $user->id, $session);
    $afterFirst = $keyring->currentEpoch((int) $user->id, $session);
    expect($afterFirst->epochId)->toBe(2);

    $keyring->appendEpoch(
        (int) $user->id,
        new GdkEpoch(3, sodium_bin2hex(random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES))),
        $session,
    );

    $handler->handle($json, (int) $user->id, $session);

    $final = $keyring->currentEpoch((int) $user->id, $session);
    expect($final->epochId)->toBe(3, 'a redelivered stale epoch must never downgrade current_epoch');

    $loaded = $keyring->loadKeyring((int) $user->id, $session);
    $countOfEpochTwo = 0;
    foreach ($loaded->epochs() as $epoch) {
        if ($epoch->epochId === 2) {
            $countOfEpochTwo++;
        }
    }
    expect($countOfEpochTwo)->toBe(1, 'epoch 2 must not be duplicated in the keyring');
});

it('adopts the peer GDK epoch over a colliding local key that has encrypted nothing', function (): void {
    $user = deliveryUser('delivery-collision-user');

    // A reused rowid can inherit an earlier test's keyring file; start empty.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    // Device B self-mints its own epoch 1, which the sender cannot detect.
    $selfMinted = $keyring->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    [$senderId, $senderSecretHex] = deliverySender(app(DatabaseManager::class), (int) $user->id);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    // Epoch ids are minted per device, so a collision has to be built here.
    $wrap = $rotation->buildGdkEpochWrap($selfMinted->epochId, $rawGdkKey, $recipientPub, $deviceB->deviceId, $senderId, $senderSecretHex);

    // The handler takes LoggerInterface by constructor, not the Log facade,
    // and is a singleton — forget the cached instance so it re-resolves
    // against the spy.
    /** @var MockInterface $logSpy */
    $logSpy = Mockery::spy(LoggerInterface::class);
    app()->instance(LoggerInterface::class, $logSpy);
    app()->forgetInstance(GdkEpochControlHandler::class);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    $logSpy->shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $ctx) use ($deviceB, $selfMinted): bool {
            return str_starts_with($message, 'GdkEpochControlHandler:')
                && str_contains($message, 'adopted the peer GDK epoch')
                && ($ctx['epoch_id'] ?? null) === $selfMinted->epochId
                && ($ctx['recipient_device_id'] ?? null) === $deviceB->deviceId;
        })
        ->once();

    $loaded = $keyring->loadKeyring((int) $user->id, $session);
    expect($loaded->keyFor($selfMinted->epochId))->toBe(sodium_bin2hex($rawGdkKey), 'the group key must replace an unused local epoch of the same id');
});

it('keeps a colliding local GDK epoch that local rows are already encrypted under', function (): void {
    $user = deliveryUser('delivery-collision-used');

    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $selfMinted = $keyring->generateAndPersist((int) $user->id, $session);
    $localKeyHex = $keyring->loadKeyring((int) $user->id, $session)->keyFor($selfMinted->epochId);

    // One row under the local epoch marks it used, which blocks replacement.
    app(DatabaseManager::class)->connection()->table('op_log_entries')->insert([
        'user_id' => (int) $user->id,
        'device_id' => $deviceB->deviceId,
        'table_name' => 'transactions',
        'pk' => 'collision-pk',
        'field' => 'note',
        'value' => 'ciphertext',
        'op_type' => 'set',
        'hlc_l' => 1,
        'hlc_c' => 0,
        'signature' => str_repeat('0', 128),
        'gdk_epoch' => $selfMinted->epochId,
        'recorded_at' => '2026-01-01 00:00:00',
    ]);

    [$senderId, $senderSecretHex] = deliverySender(app(DatabaseManager::class), (int) $user->id);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap($selfMinted->epochId, $rawGdkKey, $recipientPub, $deviceB->deviceId, $senderId, $senderSecretHex);

    /** @var MockInterface $logSpy */
    $logSpy = Mockery::spy(LoggerInterface::class);
    app()->instance(LoggerInterface::class, $logSpy);
    app()->forgetInstance(GdkEpochControlHandler::class);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    $logSpy->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $ctx): bool => str_contains($message, 'locally-USED epoch')
            && ($ctx['epoch_id'] ?? null) === $selfMinted->epochId)
        ->once();

    $loaded = $keyring->loadKeyring((int) $user->id, $session);
    expect($loaded->keyFor($selfMinted->epochId))->toBe($localKeyHex, 'a used local epoch key must survive a colliding delivery');
});

it('rejects a GDK_EPOCH_WRAP whose sender is not a confirmed device and does not append (F1)', function (): void {
    $user = deliveryUser('delivery-unconfirmed-sender-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);

    // Well-formed and correctly self-signed, but the sender is never inserted
    // into device_registry, so there is no key to verify against and the wrap
    // is rejected before any seal_open.
    $ghostSigKp = sodium_crypto_sign_keypair();
    $ghostSecretHex = sodium_bin2hex(sodium_crypto_sign_secretkey($ghostSigKp));
    $wrap = $rotation->buildGdkEpochWrap(21, $rawGdkKey, $recipientPub, $deviceB->deviceId, 'ghost-sender-not-in-registry', $ghostSecretHex);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(21))->toBeNull('a wrap from an unconfirmed/unknown sender must never be appended');
});

it('rejects a GDK_EPOCH_WRAP from a confirmed sender whose signature is corrupted and does not append (F1)', function (): void {
    $user = deliveryUser('delivery-corrupt-sig-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);

    // Confirmed sender, untouched sealed bytes: only the corrupted signature
    // can cause a rejection here.
    [$senderId, $senderSecretHex] = deliverySender(app(DatabaseManager::class), (int) $user->id);
    $wrap = $rotation->buildGdkEpochWrap(22, $rawGdkKey, $recipientPub, $deviceB->deviceId, $senderId, $senderSecretHex);

    // Flip the first hex nibble of the signature — still valid hex, wrong sig.
    $sig = (string) $wrap['sig_hex'];
    $wrap['sig_hex'] = ($sig[0] === '0' ? '1' : '0').substr($sig, 1);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(22))->toBeNull('a wrap whose signature does not verify against the confirmed sender key must never be appended');
});

it('rotate-on-A delivers the enqueued wrap to a live-connecting peer over Noise and drains the peer\'s own inbound mailbox, converging its keyring; delivered wraps are cleared', function (): void {
    $user = deliveryUser('delivery-live-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Device A only needs a registry row: the fan-out never loads an identity
    // for the acting side.
    $kpA = sodium_crypto_kx_keypair();
    $secretA = sodium_crypto_kx_secretkey($kpA);
    $publicA = sodium_crypto_kx_publickey($kpA);
    deliveryRegistryRow($db, (int) $user->id, 'device-a', sodium_bin2hex($publicA), isSelf: true);

    // Device B needs a real identity so it can actually open the sealed box.
    // generateAndPersist() marks it is_self=1; device-a is the acting device
    // here, so flip it back.
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);
    $db->connection()->table('device_registry')->where('device_id', $deviceB->deviceId)->update(['is_self' => 0]);

    $removedId = $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $user->id,
        'device_id' => 'device-c',
        'name' => 'device-c',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-07-09T10:00:00Z',
        'confirmed_at' => '2026-07-09T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-07-09T10:00:00Z',
        'updated_at' => '2026-07-09T10:00:00Z',
    ]);

    // The wrap is enqueued whether or not device-b is connected.
    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $rotation->rotateAndRevoke((int) $user->id, $removedId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $aEpoch = $keyring->currentEpoch((int) $user->id, $session);

    expect(
        $db->connection()->table('relay_mailbox')
            ->where('recipient_did', $deviceB->deviceId)
            ->whereNull('delivered_at')
            ->count()
    )->toBe(1, 'exactly one pending wrap must be enqueued for device-b');

    // Direction 1: A pushes the pending wrap to a live-connecting device-b.
    $initHs = NoiseHandshakeState::initIkInitiator(
        sodium_hex2bin($deviceB->x25519SecretKeyHex),
        sodium_hex2bin($deviceB->x25519PublicKeyHex),
        $publicA,
    );
    $respHs = NoiseHandshakeState::initIkResponder($secretA, $publicA);
    $msg1 = $initHs->writeMessage('');
    $respHs->readMessage($msg1);
    $msg2 = $respHs->writeMessage('');
    $initHs->readMessage($msg2);

    [$respSend, $respRecv, $peerStaticRevealedToResp] = $respHs->split();
    [$initSend, $initRecv, $peerStaticRevealedToInit] = $initHs->split();

    $aNoiseSession = new NoiseSession($respSend, $respRecv, $peerStaticRevealedToResp);
    $bNoiseSession = new NoiseSession($initSend, $initRecv, $peerStaticRevealedToInit);

    $aSyncSession = deliveryBareSyncSession();
    $admitted = $aSyncSession->authenticate($aNoiseSession, (int) $user->id, 'device-a');
    expect($admitted)->toBeTrue();
    expect($aSyncSession->peerDeviceId())->toBe($deviceB->deviceId);

    $handlerOnA = deliverySyncWebSocketHandler(
        (int) $user->id,
        'device-a',
        sodium_bin2hex($secretA),
        sodium_bin2hex($publicA),
    );

    $fakeClient = deliveryFakeClient(deliveryPeerReplies($bNoiseSession, acknowledged: 1, rounds: 2));
    $handlerOnA->deliverGdkEpochWraps($fakeClient, $aSyncSession);

    expect($fakeClient->sentBinary)->toHaveCount(3, 'the phase header, the pending wrap, and this side\'s own acknowledgement');
    expect(
        $db->connection()->table('relay_mailbox')
            ->where('recipient_did', $deviceB->deviceId)
            ->whereNull('delivered_at')
            ->count()
    )->toBe(0, 'the delivered wrap must be cleared (T-14-17 — no redelivery)');

    // Noise cipher states are counter-based, so frames open in the order
    // they were sealed — the phase header first, then the wrap behind it.
    $bNoiseSession->decrypt($fakeClient->sentBinary[0]);
    $deliveredPlaintext = $bNoiseSession->decrypt($fakeClient->sentBinary[1]);
    /** @var array<string, mixed> $deliveredWrap */
    $deliveredWrap = json_decode($deliveredPlaintext, true, 8, JSON_THROW_ON_ERROR);
    expect($deliveredWrap['type'])->toBe('GDK_EPOCH_WRAP');
    expect($deliveredWrap['epoch_id'])->toBe($aEpoch->epochId);
    expect($deliveredWrap['recipient_device_id'])->toBe($deviceB->deviceId);

    // The second pass adds only its phase header and acknowledgement: a cleared
    // wrap is not resent.
    $handlerOnA->deliverGdkEpochWraps($fakeClient, $aSyncSession);
    expect($fakeClient->sentBinary)->toHaveCount(5, 'a cleared wrap must never be redelivered');

    // Direction 2: device-b drains its own inbound mailbox. This needs no peer
    // session, so it is exercised on its own.
    $handlerOnB = deliverySyncWebSocketHandler(
        (int) $user->id,
        $deviceB->deviceId,
        $deviceB->x25519SecretKeyHex,
        $deviceB->x25519PublicKeyHex,
    );

    // Re-enqueue the exact blob A sent, as an external relay would have.
    $db->connection()->table('relay_mailbox')->insert([
        'sender_did' => 'device-a',
        'recipient_did' => $deviceB->deviceId,
        'blob' => $deliveredPlaintext,
        'created_at' => '2026-07-09T10:10:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-08-08T10:10:00Z',
    ]);

    $handlerOnB->drainInboundEpochWraps();

    $bKeyring = $keyring->loadKeyring((int) $user->id, $session);
    expect($bKeyring->keyFor($aEpoch->epochId))->not->toBeNull('device-b\'s own keyring must converge after draining its inbound mailbox');

    expect(
        $db->connection()->table('relay_mailbox')
            ->where('recipient_did', $deviceB->deviceId)
            ->whereNull('delivered_at')
            ->count()
    )->toBe(0, 'device-b\'s own inbound wrap must be cleared after processing');
});

// The mailbox carries pairing frames as well as wraps. Forwarding a
// PAIR_CONFIRM down this channel stopped the peer's reader dead and every wrap
// queued behind it was lost with it.
it('pushes only epoch wraps to the peer and leaves a pairing frame for the courier', function (): void {
    $user = deliveryUser('delivery-mixed-mailbox-user');

    /** @var Session $session */
    $session = app(Session::class);
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $kpA = sodium_crypto_kx_keypair();
    $secretA = sodium_crypto_kx_secretkey($kpA);
    $publicA = sodium_crypto_kx_publickey($kpA);
    deliveryRegistryRow($db, (int) $user->id, 'device-a', sodium_bin2hex($publicA), isSelf: true);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);
    $db->connection()->table('device_registry')->where('device_id', $deviceB->deviceId)->update(['is_self' => 0]);

    // The pairing frame is OLDER, so an ordered drain reaches it first —
    // precisely the ordering that used to swallow the wrap behind it.
    $db->connection()->table('relay_mailbox')->insert([
        'sender_did' => 'device-a',
        'recipient_did' => $deviceB->deviceId,
        'blob' => json_encode([
            'type' => 'PAIR_CONFIRM',
            'token_hash' => str_repeat('c', 64),
            'confirming_device_id' => 'device-a',
            'peer_device_id' => $deviceB->deviceId,
            'sig_hex' => str_repeat('d', 128),
        ], JSON_THROW_ON_ERROR),
        'created_at' => '2026-07-09T10:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-12-09T10:00:00Z',
    ]);

    [$senderId, $senderSecretHex] = deliverySender($db, (int) $user->id);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $wrap = $rotation->buildGdkEpochWrap(
        3,
        $rawGdkKey,
        sodium_hex2bin($deviceB->x25519PublicKeyHex),
        $deviceB->deviceId,
        $senderId,
        $senderSecretHex,
    );

    $db->connection()->table('relay_mailbox')->insert([
        'sender_did' => 'device-a',
        'recipient_did' => $deviceB->deviceId,
        'blob' => json_encode($wrap, JSON_THROW_ON_ERROR),
        'created_at' => '2026-07-09T11:00:00Z',
        'delivered_at' => null,
        'expires_at' => '2026-12-09T11:00:00Z',
    ]);

    $initHs = NoiseHandshakeState::initIkInitiator(
        sodium_hex2bin($deviceB->x25519SecretKeyHex),
        sodium_hex2bin($deviceB->x25519PublicKeyHex),
        $publicA,
    );
    $respHs = NoiseHandshakeState::initIkResponder($secretA, $publicA);
    $respHs->readMessage($initHs->writeMessage(''));
    $initHs->readMessage($respHs->writeMessage(''));

    [$respSend, $respRecv, $peerStaticRevealedToResp] = $respHs->split();
    [$initSend, $initRecv, $peerStaticRevealedToInit] = $initHs->split();

    $aSyncSession = deliveryBareSyncSession();
    expect($aSyncSession->authenticate(
        new NoiseSession($respSend, $respRecv, $peerStaticRevealedToResp),
        (int) $user->id,
        'device-a',
    ))->toBeTrue();

    $handlerOnA = deliverySyncWebSocketHandler(
        (int) $user->id,
        'device-a',
        sodium_bin2hex($secretA),
        sodium_bin2hex($publicA),
    );

    $bNoiseSession = new NoiseSession($initSend, $initRecv, $peerStaticRevealedToInit);

    $fakeClient = deliveryFakeClient(deliveryPeerReplies($bNoiseSession, acknowledged: 1));
    $handlerOnA->deliverGdkEpochWraps($fakeClient, $aSyncSession);

    expect($fakeClient->sentBinary)->toHaveCount(3, 'the phase header, the epoch wrap, and this side\'s acknowledgement — the pairing frame stays behind');

    /** @var array<string, mixed> $delivered */
    $header = json_decode($bNoiseSession->decrypt($fakeClient->sentBinary[0]), true, 8, JSON_THROW_ON_ERROR);
    expect($header)->toBe(['type' => 'GDK_EPOCH_PUSH', 'count' => 1]);
    /** @var array<string, mixed> $delivered */
    $delivered = json_decode($bNoiseSession->decrypt($fakeClient->sentBinary[1]), true, 8, JSON_THROW_ON_ERROR);

    expect($delivered['type'])->toBe('GDK_EPOCH_WRAP')
        ->and($delivered['epoch_id'])->toBe(3);

    // The pairing frame stays pending: the courier that polls this mailbox
    // over HTTP is the only thing that knows how to act on it.
    $pending = $db->connection()->table('relay_mailbox')
        ->where('recipient_did', $deviceB->deviceId)
        ->whereNull('delivered_at')
        ->pluck('blob')
        ->all();

    expect($pending)->toHaveCount(1)
        ->and($pending[0])->toContain('PAIR_CONFIRM');
});
