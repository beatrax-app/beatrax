<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\FileEncryptor;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Identity\DeviceIdentityDto;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// device_registry is the permanent trust store, and confirmedDevices() sorts it
// by paired_at — a TEXT column SQLite compares as a string. An offset stamp
// sorts by its own local hour digits against a Zulu one, so the two forms may
// not share the column: see .docs/features/sync/architecture.md, "Device registry".

const DEVICE_STAMP_ZULU_SHAPE = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

const DEVICE_STAMP_KEK = 'k';

const DEVICE_STAMP_MIGRATION = 'Modules/Sync/Database/Migrations/2026_08_22_000002_rewrite_sync_stamps_as_zulu.php';

function deviceStampUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('device-stamp-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // RefreshDatabase resets the database but not the filesystem, and user ids
    // are reused across runs, so an earlier run's key-file can still be there.
    foreach ((array) glob(UserDataPathService::appPath("sync/identity/{$user->id}.enc*")) as $stale) {
        @unlink((string) $stale);
    }

    return $user;
}

function deviceStampEastOfUtc(string $instant): void
{
    date_default_timezone_set('Europe/Amsterdam');
    CarbonImmutable::setTestNow(CarbonImmutable::parse($instant));
}

function deviceStampRow(int $userId): object
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('is_self', 1)
        ->first();

    expect($row)->not->toBeNull();

    return (object) $row;
}

function deviceStampPeerRow(int $userId, string $deviceId, string $name, string $pairedAt): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('device_registry')->insert([
        'user_id' => $userId,
        'device_id' => $deviceId,
        'name' => $name,
        'ed25519_public_key_hex' => str_repeat('a', 64),
        'x25519_public_key_hex' => str_repeat('b', 64),
        'safety_number_words' => '',
        'is_self' => 0,
        'paired_at' => $pairedAt,
        'confirmed_at' => $pairedAt,
        'last_seen_at' => null,
        'created_at' => $pairedAt,
        'updated_at' => $pairedAt,
    ]);
}

// Re-seals the key-file with the stamp an earlier version of the minting code
// wrote into it, which is the value restoreSelfRow() puts back on the row.
function deviceStampLegacyKeyFile(int $userId, DeviceIdentityDto $identity, string $createdAt): void
{
    $payload = $identity->toArray();
    $payload['created_at'] = $createdAt;

    $encPath = UserDataPathService::appPath("sync/identity/{$userId}.enc");
    $plainPath = $encPath.'.legacy-plain';

    file_put_contents($plainPath, json_encode($payload, JSON_THROW_ON_ERROR));

    try {
        app(FileEncryptor::class)->encryptWithKey($plainPath, $encPath, str_repeat(DEVICE_STAMP_KEK, 32));
    } finally {
        @unlink($plainPath);
    }
}

function deviceStampMigration(): Migration
{
    $migration = require base_path(DEVICE_STAMP_MIGRATION);
    assert($migration instanceof Migration);

    return $migration;
}

beforeEach(function (): void {
    $this->originalTimezone = date_default_timezone_get();
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    date_default_timezone_set($this->originalTimezone);
});

it('writes the self-row stamps in Zulu when a device mints its identity', function (): void {
    deviceStampEastOfUtc('2026-06-15T18:30:00Z');
    $user = deviceStampUser('device-stamp-mint');

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat(DEVICE_STAMP_KEK, 32));

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    $row = deviceStampRow((int) $user->id);

    expect($row->paired_at)->toMatch(DEVICE_STAMP_ZULU_SHAPE);
    expect($row->confirmed_at)->toMatch(DEVICE_STAMP_ZULU_SHAPE);
    expect($row->created_at)->toMatch(DEVICE_STAMP_ZULU_SHAPE);
});

it('normalises the stamp a legacy key-file carries when sync is switched back on', function (): void {
    deviceStampEastOfUtc('2026-06-15T18:30:00Z');
    $user = deviceStampUser('device-stamp-restore');

    /** @var Session $session */
    $session = app(Session::class);
    AppLockTestHarness::unlock($session, str_repeat(DEVICE_STAMP_KEK, 32));

    $service = app(DeviceIdentityService::class);
    $identity = $service->generateAndPersist((int) $user->id, $session);

    deviceStampLegacyKeyFile((int) $user->id, $identity, '2026-06-15T20:30:00+02:00');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $db->connection()->table('device_registry')->where('user_id', $user->id)->delete();

    $service->generateAndPersist((int) $user->id, $session);

    $row = deviceStampRow((int) $user->id);

    expect($row->paired_at)->toBe('2026-06-15T18:30:00Z');
    expect($row->confirmed_at)->toBe('2026-06-15T18:30:00Z');
    expect($row->created_at)->toBe('2026-06-15T18:30:00Z');
});

it('rewrites the rows an earlier version wrote at a local offset', function (): void {
    deviceStampEastOfUtc('2026-06-15T18:30:00Z');
    $user = deviceStampUser('device-stamp-legacy-rows');

    deviceStampPeerRow((int) $user->id, 'legacy-peer', 'Legacy phone', '2026-06-15T20:30:00+02:00');

    deviceStampMigration()->up();

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $row = (object) $db->connection()->table('device_registry')->where('device_id', 'legacy-peer')->first();

    expect($row->paired_at)->toBe('2026-06-15T18:30:00Z');
    expect($row->confirmed_at)->toBe('2026-06-15T18:30:00Z');
    expect($row->created_at)->toBe('2026-06-15T18:30:00Z');
});

it('orders the device list by the instant each device paired, not by its digits', function (): void {
    deviceStampEastOfUtc('2026-06-15T18:30:00Z');
    $user = deviceStampUser('device-stamp-order');

    // 18:30Z written at +02:00 reads as "20:30", so it sorts AFTER a 19:00Z
    // sibling that is genuinely half an hour younger.
    deviceStampPeerRow((int) $user->id, 'paired-first', 'Older phone', '2026-06-15T20:30:00+02:00');
    deviceStampPeerRow((int) $user->id, 'paired-second', 'Newer phone', '2026-06-15T19:00:00Z');

    deviceStampMigration()->up();

    $names = array_map(
        static fn (object $row): string => is_string($row->name) ? $row->name : '',
        app(DeviceRegistryService::class)->confirmedDevices((int) $user->id),
    );

    expect($names)->toBe(['Older phone', 'Newer phone']);
});
