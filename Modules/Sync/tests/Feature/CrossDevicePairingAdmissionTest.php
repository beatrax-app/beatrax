<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Internal\Pairing\PairingTokenService;
use Modules\Sync\Public\Services\DeviceRegistryService;
use Modules\Sync\Tests\Support\PairingSafetyDigest;

uses(RefreshDatabase::class);

/**
 * @link ../../../../.docs/features/sync/cross-device-pairing-confirm.md
 */
function crossDeviceUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-07-14 09:00:00');
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

it('admits the initiator (desktop) into the phone-simulated local registry after both-confirm (G1 + G2)', function (): void {
    $user = crossDeviceUser('cross-device-admission');

    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::now()->toIso8601String();
    $db->connection()->table('device_registry')->insert([
        'user_id' => (int) $user->id,
        'device_id' => 'phone-self',
        'name' => 'This phone',
        'ed25519_public_key_hex' => str_repeat('9', 64),
        'x25519_public_key_hex' => str_repeat('8', 64),
        'safety_number_words' => 'phone self words',
        'is_self' => 1,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $token = bin2hex(random_bytes(16));
    $initiatorEd = str_repeat('a', 64);
    $initiatorKx = str_repeat('b', 64);

    // Without this seed, accept() would find no pending row at all on a
    // genuinely separate database.
    $seeded = $service->seedFromInitiator((int) $user->id, 'desktop-initiator', $initiatorEd, $initiatorKx, $token);
    expect($seeded)->not->toBeFalse();

    $accepted = $service->accept($token, (int) $user->id, 'phone-self', str_repeat('9', 64), str_repeat('8', 64));
    expect($accepted)->not->toBeFalse();

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    expect($row->state)->toBe('awaiting_confirm');
    expect($row->initiator_seeded_at)->not->toBeNull();

    // One database stands in for both sides here: the second confirm() is the
    // desktop's confirm signal arriving on this same row.
    $service->confirm((int) $row->id, (int) $user->id, 'phone-self', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));
    $state = $service->confirm((int) $row->id, (int) $user->id, 'desktop-initiator', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));

    expect($state)->toBe('confirmed');

    $initiatorRow = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'desktop-initiator')
        ->first();

    expect($initiatorRow)->not->toBeNull();
    expect($initiatorRow->confirmed_at)->not->toBeNull();
    expect($initiatorRow->is_self)->toBe(0);
    expect($initiatorRow->ed25519_public_key_hex)->toBe($initiatorEd);
    expect($initiatorRow->x25519_public_key_hex)->toBe($initiatorKx);

    /** @var DeviceRegistryService $registry */
    $registry = app(DeviceRegistryService::class);
    expect($registry->deviceKeys((int) $user->id))->toHaveKey('desktop-initiator');
    expect($registry->deviceX25519Keys((int) $user->id))->toHaveKey('desktop-initiator');
});

it('refuses to admit an initiator whose device_id collides with the local self-row', function (): void {
    $user = crossDeviceUser('cross-device-self-collision');

    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $now = CarbonImmutable::now()->toIso8601String();
    $db->connection()->table('device_registry')->insert([
        'user_id' => (int) $user->id,
        'device_id' => 'colliding-device',
        'name' => 'This phone',
        'ed25519_public_key_hex' => str_repeat('1', 64),
        'x25519_public_key_hex' => str_repeat('2', 64),
        'safety_number_words' => 'self words',
        'is_self' => 1,
        'paired_at' => $now,
        'confirmed_at' => $now,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $token = bin2hex(random_bytes(16));

    // The scanned QR happens to carry the SAME device_id as this device's
    // own self-row (a crafted/pathological QR).
    $service->seedFromInitiator((int) $user->id, 'colliding-device', str_repeat('a', 64), str_repeat('b', 64), $token);
    $service->accept($token, (int) $user->id, 'phone-self-2', str_repeat('c', 64), str_repeat('d', 64));

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    $service->confirm((int) $row->id, (int) $user->id, 'phone-self-2', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));
    $service->confirm((int) $row->id, (int) $user->id, 'colliding-device', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));

    $self = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 1)
        ->first();

    expect($self->ed25519_public_key_hex)->toBe(str_repeat('1', 64));
    expect($self->x25519_public_key_hex)->toBe(str_repeat('2', 64));

    $nonSelfForColliding = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'colliding-device')
        ->where('is_self', 0)
        ->count();

    expect($nonSelfForColliding)->toBe(0);
});

it('seedFromInitiator is idempotent for a repeated scan of the same physical code', function (): void {
    $user = crossDeviceUser('cross-device-idempotent-seed');

    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $token = bin2hex(random_bytes(16));

    $first = $service->seedFromInitiator((int) $user->id, 'desktop-x', str_repeat('a', 64), str_repeat('b', 64), $token);
    $second = $service->seedFromInitiator((int) $user->id, 'desktop-x', str_repeat('a', 64), str_repeat('b', 64), $token);

    expect($first)->not->toBeFalse();
    expect($second)->not->toBeFalse();
    expect($first->id)->toBe($second->id);

    expect($db->connection()->table('pairing_tokens')->where('user_id', $user->id)->count())->toBe(1);
});

it('rejects malformed initiator key material without seeding a row', function (): void {
    $user = crossDeviceUser('cross-device-malformed-key');

    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $result = $service->seedFromInitiator((int) $user->id, 'desktop-bad', 'not-hex', 'also-not-hex', bin2hex(random_bytes(16)));

    expect($result)->toBeFalse();
    expect($db->connection()->table('pairing_tokens')->where('user_id', $user->id)->count())->toBe(0);
});

it('leaves the desktop-side single-database flow unchanged: admitResponderDevice still fires unconditionally for a plain issue()-created row', function (): void {
    $user = crossDeviceUser('cross-device-desktop-unchanged');

    /** @var PairingTokenService $service */
    $service = app(PairingTokenService::class);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Plain issue(), not seedFromInitiator(): initiator_seeded_at stays NULL.
    $token = $service->issue((int) $user->id, 'device-init', str_repeat('a', 64), str_repeat('b', 64));
    $service->accept($token, (int) $user->id, 'device-resp', str_repeat('c', 64), str_repeat('d', 64));

    $row = $db->connection()->table('pairing_tokens')->where('user_id', $user->id)->first();
    expect($row->initiator_seeded_at)->toBeNull();

    $service->confirm((int) $row->id, (int) $user->id, 'device-init', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));
    $service->confirm((int) $row->id, (int) $user->id, 'device-resp', PairingSafetyDigest::forToken((int) $row->id, (int) $user->id));

    $responderRow = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'device-resp')
        ->first();
    expect($responderRow)->not->toBeNull();
    expect($responderRow->confirmed_at)->not->toBeNull();

    // 'device-init' is a placeholder id, not a real device, and
    // initiator_seeded_at was never set — it must never be admitted.
    $initiatorRow = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', 'device-init')
        ->first();
    expect($initiatorRow)->toBeNull('a plain issue()-created row must never admit a phantom initiator device_registry row');
});
