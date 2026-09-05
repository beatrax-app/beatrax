<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\Clock;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\Crypto\GdkEpochControlHandler;
use Modules\Sync\Internal\Crypto\GdkKeyringService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Crypto\GdkWrapRecipient;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Merge\OpLogReplayer;
use Modules\Sync\Internal\OpLog\OpLogEntry;
use Modules\Sync\Internal\OpLog\OpType;
use Modules\Sync\Internal\OpLog\QuarantineReason;
use Modules\Sync\Internal\Signing\DeviceKeySigner;
use Modules\Sync\Internal\Transport\Frame\TransportFramer;
use Modules\Sync\Internal\Transport\Noise\NoiseHandshakeState;
use Modules\Sync\Internal\Transport\Noise\NoiseSession;
use Modules\Sync\Internal\Transport\SyncSession;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// The boundary the whole introduction rests on: a relayed identity, once
// confirmed, grants signature verification and NOTHING else. These pin both
// halves — that it does verify, and that it reaches no other gate.

const INTRODUCED_DEVICE_ID = 'phone-that-was-replaced';

function introducedUser(string $suffix): User
{
    return User::query()->create([
        'username' => 'introduced-'.$suffix,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return string The introduced device's Ed25519 secret key, hex.
 */
function introduce(DatabaseManager $db, int $userId, bool $confirmed): string
{
    $keypair = sodium_crypto_sign_keypair();

    $db->connection()->table('device_introductions')->insert([
        'user_id' => $userId,
        'device_id' => INTRODUCED_DEVICE_ID,
        'name' => 'Old phone',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey($keypair)),
        'safety_number_words' => 'abandon ability able about above absent',
        'introduced_by_device_id' => 'the-mac',
        'introduced_at' => '2026-09-05T10:00:00Z',
        'verification_confirmed_at' => $confirmed ? '2026-09-05T10:05:00Z' : null,
        'created_at' => '2026-09-05T10:00:00Z',
        'updated_at' => '2026-09-05T10:00:00Z',
    ]);

    return sodium_bin2hex(sodium_crypto_sign_secretkey($keypair));
}

/**
 * @return list<OpLogEntry>
 */
function introducedGoalOps(DeviceKeySigner $signer, string $secretKeyHex, int $userId): array
{
    $secretKey = sodium_hex2bin($secretKeyHex);
    $entries = [];
    $hlcL = 1_790_000_000_000;

    $fields = [
        'name' => json_encode('New roof', JSON_THROW_ON_ERROR),
        'target_minor' => json_encode(1500000, JSON_THROW_ON_ERROR),
        'target_currency' => json_encode('EUR', JSON_THROW_ON_ERROR),
        'start_date' => json_encode('2026-06-01', JSON_THROW_ON_ERROR),
        'target_date' => json_encode('2027-06-01', JSON_THROW_ON_ERROR),
    ];

    foreach ($fields as $field => $value) {
        $make = static fn (string $signature): OpLogEntry => new OpLogEntry(
            table: 'goals',
            pk: 909,
            field: $field,
            value: $value,
            hlcL: $hlcL,
            hlcC: 0,
            deviceId: INTRODUCED_DEVICE_ID,
            opType: OpType::CreateRow,
            signature: $signature,
            userId: $userId,
        );

        $entries[] = $make($signer->sign($make('')->signingPayload(), $secretKey));
        $hlcL++;
    }

    return $entries;
}

function introducedQuarantineCount(DatabaseManager $db, int $userId, QuarantineReason $reason): int
{
    return $db->connection()->table('op_log_quarantine')
        ->where('user_id', $userId)
        ->where('reason', $reason->value)
        ->count();
}

it('verifies an op signed by a device the reader confirmed from an introduction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = introducedUser('verifies');
    $userId = (int) $user->id;

    $secretKeyHex = introduce($db, $userId, confirmed: true);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: $registry->signatureVerificationKeys($userId),
        rules: new MergeRulesRegistry,
    );
    $replayer->replay(introducedGoalOps(app(DeviceKeySigner::class), $secretKeyHex, $userId), $userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(1)
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(5)
        ->and($db->connection()->table('op_log_quarantine')->where('user_id', $userId)->count())->toBe(0);
});

it('verifies nothing at all until the reader confirms the introduction', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = introducedUser('unconfirmed');
    $userId = (int) $user->id;

    $secretKeyHex = introduce($db, $userId, confirmed: false);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    expect($registry->signatureVerificationKeys($userId))->toBe([]);

    $replayer = new OpLogReplayer(
        db: $db,
        deviceKeys: $registry->signatureVerificationKeys($userId),
        rules: new MergeRulesRegistry,
    );
    $replayer->replay(introducedGoalOps(app(DeviceKeySigner::class), $secretKeyHex, $userId), $userId);

    expect($db->connection()->table('goals')->where('user_id', $userId)->count())->toBe(0)
        ->and($db->connection()->table('op_log_entries')->where('user_id', $userId)->count())->toBe(0)
        ->and(introducedQuarantineCount($db, $userId, QuarantineReason::MissingDeviceKey))->toBe(5);
});

it('leaves a confirmed introduction out of every map that is not the signature gate', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = introducedUser('maps');
    $userId = (int) $user->id;

    introduce($db, $userId, confirmed: true);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    expect(array_keys($registry->signatureVerificationKeys($userId)))->toBe([INTRODUCED_DEVICE_ID])
        ->and($registry->deviceKeys($userId))->toBe([])
        ->and($registry->deviceX25519Keys($userId))->toBe([])
        ->and($registry->retainedDeviceKeys($userId))->toBe([])
        ->and($registry->otherDeviceNames($userId))->toBe([])
        ->and($registry->confirmedDevices($userId))->toBe([])
        ->and($registry->isStillConfirmed($userId, INTRODUCED_DEVICE_ID))->toBeFalse();
});

it('holds no transport key for an introduced device, so a widened query would find nothing', function (): void {
    $columns = Schema::getColumnListing('device_introductions');

    expect($columns)->not->toContain('x25519_public_key_hex')
        ->and($columns)->not->toContain('confirmed_at')
        ->and($columns)->toContain('verification_confirmed_at')
        ->and(array_filter($columns, static fn (string $c): bool => str_contains($c, 'x25519')))->toBe([]);
});

it('refuses a Noise session to a device that is only introduced', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = introducedUser('transport');
    $userId = (int) $user->id;

    introduce($db, $userId, confirmed: true);

    $peerKp = sodium_crypto_kx_keypair();
    $localKp = sodium_crypto_kx_keypair();
    $localPublic = sodium_crypto_kx_publickey($localKp);

    // A confirmed self row, so the map the gate reads is NOT empty: the refusal
    // below has to be "this key is not in it", never "there was nothing to
    // compare against", which would pass with the introduction removed.
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => 'this-device',
        'name' => 'This device',
        'ed25519_public_key_hex' => sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        'x25519_public_key_hex' => sodium_bin2hex($localPublic),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 1,
        'paired_at' => '2026-09-05T10:00:00Z',
        'confirmed_at' => '2026-09-05T10:00:00Z',
        'created_at' => '2026-09-05T10:00:00Z',
        'updated_at' => '2026-09-05T10:00:00Z',
    ]);

    $initHs = NoiseHandshakeState::initIkInitiator(
        sodium_crypto_kx_secretkey($peerKp),
        sodium_crypto_kx_publickey($peerKp),
        $localPublic,
    );
    $respHs = NoiseHandshakeState::initIkResponder(sodium_crypto_kx_secretkey($localKp), $localPublic);

    $respHs->readMessage($initHs->writeMessage(''));
    $initHs->readMessage($respHs->writeMessage(''));

    [$send, $recv, $peerStatic] = $respHs->split();

    $session = new SyncSession(
        registryService: app(DeviceRegistryService::class),
        signer: new DeviceKeySigner,
        replayer: new OpLogReplayer(db: $db),
        framer: new TransportFramer,
        db: $db,
        clock: app(Clock::class),
    );

    $admitted = $session->authenticate(new NoiseSession($send, $recv, $peerStatic), $userId, 'this-device');

    expect(app(DeviceRegistryService::class)->deviceX25519Keys($userId))->toHaveCount(1)
        ->and($admitted)->toBeFalse()
        ->and($session->peerDeviceId())->toBeNull()
        ->and($session->status())->toBe('failed')
        ->and($db->connection()->table('sync_sessions')->where('user_id', $userId)->value('peer_device_id'))
        ->toBe('unknown');
});

it('never appends an epoch key a device that is only introduced signed the wrap for', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = introducedUser('epoch');
    $userId = (int) $user->id;

    $secretKeyHex = introduce($db, $userId, confirmed: true);

    /** @var Session $session */
    $session = app(Session::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);
    /** @var DeviceIdentityDto $self */
    $self = $identityService->generateAndPersist($userId, $session);

    /** @var GdkRotationService $rotation */
    $rotation = app(GdkRotationService::class);

    // Correctly sealed to this device and correctly self-signed. The only thing
    // wrong with it is who signed it, which is the whole of the test.
    $wrap = $rotation->buildGdkEpochWrap(
        4242,
        random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES),
        new GdkWrapRecipient($self->deviceId, sodium_hex2bin($self->x25519PublicKeyHex)),
        INTRODUCED_DEVICE_ID,
        $secretKeyHex,
    );

    app(GdkEpochControlHandler::class)->handle(json_encode($wrap, JSON_THROW_ON_ERROR), $userId, $session);

    /** @var GdkKeyringService $keyring */
    $keyring = app(GdkKeyringService::class);

    expect($keyring->loadKeyring($userId, $session)->keyFor(4242))->toBeNull();
});

it('stops verifying for a device the reader paired with and then removed', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $user = introducedUser('removed');
    $userId = (int) $user->id;

    introduce($db, $userId, confirmed: true);

    $pairedKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair()));

    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => INTRODUCED_DEVICE_ID,
        'name' => 'Old phone',
        'ed25519_public_key_hex' => $pairedKeyHex,
        'x25519_public_key_hex' => sodium_bin2hex(sodium_crypto_kx_publickey(sodium_crypto_kx_keypair())),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-09-05T11:00:00Z',
        'confirmed_at' => '2026-09-05T11:00:00Z',
        'created_at' => '2026-09-05T11:00:00Z',
        'updated_at' => '2026-09-05T11:00:00Z',
    ]);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);

    expect($registry->signatureVerificationKeys($userId))->toBe([INTRODUCED_DEVICE_ID => $pairedKeyHex]);

    $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('device_id', INTRODUCED_DEVICE_ID)
        ->update(['confirmed_at' => null]);

    expect($registry->signatureVerificationKeys($userId))->toBe([]);
});
