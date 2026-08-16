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

/*
 * GdkEpochDeliveryTest — CRYPT-02 distribution: receive-side validate/open/
 * append of an inbound GDK_EPOCH_WRAP control message (Task 1,
 * GdkEpochControlHandler) plus the live-session + relay-mailbox delivery
 * wiring in SyncWebSocketHandler (Task 2). 14-VALIDATION.md CRYPT-02
 * delivery row.
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
 * Minimal WebsocketClient fake — only sendBinary() is exercised by
 * SyncWebSocketHandler::deliverGdkEpochWraps(); every other interface
 * method is unused in this test and throws if ever called.
 */
function deliveryFakeClient(): WebsocketClient
{
    return new class implements IteratorAggregate, WebsocketClient
    {
        /** @var list<string> */
        public array $sentBinary = [];

        public function receive(?Cancellation $cancellation = null): ?WebsocketMessage
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

// ---------------------------------------------------------------------
// Task 1 — GdkEpochControlHandler: validate, open, append.
// ---------------------------------------------------------------------

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

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(7, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    $loaded = $keyring->loadKeyring((int) $user->id, $session);

    expect($loaded->keyFor(7))->toBe(sodium_bin2hex($rawGdkKey));

    // B can decrypt an entry written under the newly-appended epoch.
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

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    // Sealed to a DIFFERENT (foreign) device's public key, not this device's.
    $foreignPub = sodium_crypto_box_publickey(sodium_crypto_box_keypair());
    $wrap = $rotation->buildGdkEpochWrap(9, $rawGdkKey, $foreignPub, 'a-foreign-device-id');

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

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(11, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    // Tamper: flip the last byte of the sealed box.
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

    // Baseline captured BEFORE handle() — asserted against a delta rather
    // than an assumed-empty keyring, since the on-disk keyring file is
    // keyed only by user id (Plan 02's documented cross-test filesystem
    // isolation gap: SQLite rowids can be reused across RefreshDatabase
    // transaction rollbacks within one process, so a prior test's user id
    // may already have a keyring file on disk for this same id).
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
    $keyring->generateAndPersist((int) $user->id, $session); // epoch 1

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(2, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $json = json_encode($wrap, JSON_THROW_ON_ERROR);

    $handler->handle($json, (int) $user->id, $session);
    $afterFirst = $keyring->currentEpoch((int) $user->id, $session);
    expect($afterFirst->epochId)->toBe(2);

    // Advance further, then re-deliver the STALE epoch 2 wrap again.
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

/*
 * MEDIUM-02 (15-import-join-REVIEW.md) — an already-present epoch_id is
 * EXACTLY where a desktop ADD-device fan-out collides: the peer may have
 * self-minted its OWN epoch 1 under a DIFFERENT key. Dropping the inbound
 * wrap, as this once did, left every row the desktop encrypted under its
 * epoch 1 permanently unreadable on that peer. The group's key wins when
 * the local one has encrypted nothing.
 */
it('adopts the peer GDK epoch over a colliding local key that has encrypted nothing', function (): void {
    $user = deliveryUser('delivery-collision-user');

    // Cross-test filesystem-isolation gap (documented at the top of the
    // "is idempotent" test above): SQLite rowids can be reused across
    // RefreshDatabase transaction rollbacks within one process, so a prior
    // test's on-disk keyring file may already exist for this same reused
    // user id. Start from a genuinely empty on-disk state.
    @unlink(UserDataPathService::appPath('sync/gdk/'.$user->id.'.enc'));

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);
    // Device B already self-minted its OWN epoch 1 (mirrors a normal,
    // non-import self-minting peer — a scenario the desktop cannot
    // distinguish from an import peer without a cross-device signal,
    // see HIGH-01).
    $keyring->generateAndPersist((int) $user->id, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    // The desktop's OWN, DIFFERENT epoch-1 key is now fanned out and
    // collides with device B's already-present epoch 1.
    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(1, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    // The handler logs via a constructor-injected LoggerInterface, NOT the
    // Log facade — bind a container spy so the injected instance is captured.
    // GdkEpochControlHandler is a singleton (SyncServiceProvider), so forget
    // any cached instance and let it re-resolve with the spy logger.
    /** @var MockInterface $logSpy */
    $logSpy = Mockery::spy(LoggerInterface::class);
    app()->instance(LoggerInterface::class, $logSpy);
    app()->forgetInstance(GdkEpochControlHandler::class);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    $logSpy->shouldHaveReceived('warning')
        ->withArgs(function (string $message, array $ctx) use ($deviceB): bool {
            return str_starts_with($message, 'GdkEpochControlHandler:')
                && str_contains($message, 'adopted the peer GDK epoch')
                && ($ctx['epoch_id'] ?? null) === 1
                && ($ctx['recipient_device_id'] ?? null) === $deviceB->deviceId;
        })
        ->once();

    // Nothing local was encrypted under the self-minted epoch 1, so the
    // group's key replaces it and the desktop's rows become readable.
    $loaded = $keyring->loadKeyring((int) $user->id, $session);
    expect($loaded->keyFor(1))->toBe(sodium_bin2hex($rawGdkKey), 'the group key must replace an unused local epoch of the same id');
});

/*
 * The mirror case: once a row IS encrypted under the local epoch, that key
 * is the only way to read it, so adopting the peer's would trade one set of
 * unreadable rows for another. The conflict is raised instead of resolved.
 */
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
    $keyring->generateAndPersist((int) $user->id, $session);
    $localKeyHex = $keyring->loadKeyring((int) $user->id, $session)->keyFor(1);

    // One durable row encrypted under the local epoch 1 is all it takes.
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
        'gdk_epoch' => 1,
        'recorded_at' => '2026-01-01 00:00:00',
    ]);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $recipientPub = sodium_hex2bin($deviceB->x25519PublicKeyHex);
    $wrap = $rotation->buildGdkEpochWrap(1, $rawGdkKey, $recipientPub, $deviceB->deviceId);

    /** @var MockInterface $logSpy */
    $logSpy = Mockery::spy(LoggerInterface::class);
    app()->instance(LoggerInterface::class, $logSpy);
    app()->forgetInstance(GdkEpochControlHandler::class);

    /** @var GdkEpochControlHandler $handler */
    $handler = app(GdkEpochControlHandler::class);
    $handler->handle(json_encode($wrap, JSON_THROW_ON_ERROR), (int) $user->id, $session);

    $logSpy->shouldHaveReceived('error')
        ->withArgs(fn (string $message, array $ctx): bool => str_contains($message, 'locally-USED epoch')
            && ($ctx['epoch_id'] ?? null) === 1)
        ->once();

    $loaded = $keyring->loadKeyring((int) $user->id, $session);
    expect($loaded->keyFor(1))->toBe($localKeyHex, 'a used local epoch key must survive a colliding delivery');
});

// ---------------------------------------------------------------------
// Task 2 — SyncWebSocketHandler::deliverGdkEpochWraps() wiring.
// ---------------------------------------------------------------------

it('rotate-on-A delivers the enqueued wrap to a live-connecting peer over Noise and drains the peer\'s own inbound mailbox, converging its keyring; delivered wraps are cleared', function (): void {
    $user = deliveryUser('delivery-live-user');

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Device A: the acting/self device that runs rotateAndRevoke(). Only a
    // device_registry row is needed — GdkRotationService's fan-out never
    // touches DeviceIdentityService/DeviceIdentityLoader for the acting side.
    $kpA = sodium_crypto_kx_keypair();
    $secretA = sodium_crypto_kx_secretkey($kpA);
    $publicA = sodium_crypto_kx_publickey($kpA);
    deliveryRegistryRow($db, (int) $user->id, 'device-a', sodium_bin2hex($publicA), isSelf: true);

    // Device B: a REAL DeviceIdentityService identity (real X25519 secret
    // key on "its own disk") so GdkEpochControlHandler can genuinely open
    // the sealed box addressed to it. generateAndPersist() inserts its own
    // is_self=1 row — flip it to is_self=0 since device-a is the true
    // acting/self device for this rotation.
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $deviceB */
    $deviceB = $identityService->generateAndPersist((int) $user->id, $session);
    $db->connection()->table('device_registry')->where('device_id', $deviceB->deviceId)->update(['is_self' => 0]);

    // Device C: about to be removed.
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

    // Rotation on A: revokes device-c, rotates the GDK epoch, and enqueues
    // exactly one sealed-box wrap for device-b on the ZK-pure RelayMailbox
    // (Plan 05) — regardless of whether device-b happens to be connected.
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

    // --- Direction 1: A's live-connect delivery step pushes the pending
    // wrap to a connecting device-b over an authenticated Noise session. ---
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

    $fakeClient = deliveryFakeClient();
    $handlerOnA->deliverGdkEpochWraps($fakeClient, $aSyncSession);

    // Frame 0 is the GDK_EPOCH_PUSH header announcing the phase and its
    // count; the wrap itself follows it.
    expect($fakeClient->sentBinary)->toHaveCount(2, 'the phase header plus the pending wrap must be pushed');
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

    // Re-running delivery must not resend an already-cleared wrap — the
    // second pass adds its phase header (count 0) and nothing else.
    $handlerOnA->deliverGdkEpochWraps($fakeClient, $aSyncSession);
    expect($fakeClient->sentBinary)->toHaveCount(3, 'a cleared wrap must never be redelivered');

    // --- Direction 2: device-b's OWN daemon drains ITS OWN inbound mailbox
    // and routes each wrap through GdkEpochControlHandler, converging its
    // local keyring under its own KEK. ---
    $handlerOnB = deliverySyncWebSocketHandler(
        (int) $user->id,
        $deviceB->deviceId,
        $deviceB->x25519SecretKeyHex,
        $deviceB->x25519PublicKeyHex,
    );

    // Direction 2 is wire-independent: draining this device's own mailbox
    // needs no peer session at all, so it is exercised on its own.

    // Simulate the wrap having reached device-b's own local relay_mailbox
    // (e.g. via an external relay or a peer forwarding it) by decrypting
    // what A actually sent and re-enqueuing the SAME opaque blob addressed
    // to device-b locally.
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

// The relay mailbox carries two protocols at once: the epoch wraps this step
// owns, and the pairing frames the relay endpoint stores for the same peer.
// Forwarding indiscriminately confirmed a leftover PAIR_CONFIRM away down a
// channel whose reader discards it — and because that reader stopped at the
// first thing it did not recognise, every wrap queued behind it went too. The
// phone was then left holding no epoch key at all, quarantining the very
// history it had just been sent.
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

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);
    $rawGdkKey = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);
    $wrap = $rotation->buildGdkEpochWrap(
        3,
        $rawGdkKey,
        sodium_hex2bin($deviceB->x25519PublicKeyHex),
        $deviceB->deviceId,
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

    $fakeClient = deliveryFakeClient();
    $handlerOnA->deliverGdkEpochWraps($fakeClient, $aSyncSession);

    expect($fakeClient->sentBinary)->toHaveCount(2, 'only the phase header and the epoch wrap belong on this channel');

    $bNoiseSession = new NoiseSession($initSend, $initRecv, $peerStaticRevealedToInit);
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
