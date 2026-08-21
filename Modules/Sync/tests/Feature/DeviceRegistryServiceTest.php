<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Models\User;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// deviceKeys() must return confirmed rows only. An unconfirmed device
// appearing in that map would put a key the user never verified into the set
// the replayer treats as trusted.

function registryUser(string $username = 'registry-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('registry-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

/**
 * @return int inserted device_registry row id
 */
function seedDevice(DatabaseManager $db, int $userId, string $deviceId, string $edHex, ?string $confirmedAt): int
{
    return $db->connection()->table('device_registry')->insertGetId([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => 'Seed '.$deviceId,
        'ed25519_public_key_hex' => $edHex,
        'x25519_public_key_hex' => str_repeat('0', 64),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'paired_at' => '2026-06-15T10:00:00Z',
        'confirmed_at' => $confirmedAt,
        'last_seen_at' => null,
        'created_at' => '2026-06-15T10:00:00Z',
        'updated_at' => '2026-06-15T10:00:00Z',
    ]);
}

it('returns only confirmed devices keyed by device_id', function (): void {
    $user = registryUser();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $confirmedEd = str_repeat('a', 64);
    seedDevice($db, (int) $user->id, 'device-confirmed', $confirmedEd, '2026-06-15T10:05:00Z');
    seedDevice($db, (int) $user->id, 'device-unconfirmed', str_repeat('b', 64), null);

    /** @var DeviceRegistryService $service */
    $service = $this->app->make(DeviceRegistryService::class);

    $keys = $service->deviceKeys((int) $user->id);

    expect($keys)->toHaveKey('device-confirmed');
    expect($keys['device-confirmed'])->toBe($confirmedEd);

    expect($keys)->not->toHaveKey('device-unconfirmed');
});

it('scopes the device-key map to the requested user', function (): void {
    $userA = registryUser('registry-a');
    $userB = registryUser('registry-b');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    seedDevice($db, (int) $userA->id, 'device-a', str_repeat('a', 64), '2026-06-15T10:05:00Z');
    seedDevice($db, (int) $userB->id, 'device-b', str_repeat('c', 64), '2026-06-15T10:05:00Z');

    /** @var DeviceRegistryService $service */
    $service = $this->app->make(DeviceRegistryService::class);

    $keysA = $service->deviceKeys((int) $userA->id);

    expect($keysA)->toHaveKey('device-a');
    expect($keysA)->not->toHaveKey('device-b');
});
