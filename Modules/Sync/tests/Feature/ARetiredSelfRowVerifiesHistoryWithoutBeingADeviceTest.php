<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Crypto\GdkRotationService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Public\Services\DeviceRegistryService;

uses(RefreshDatabase::class);

// The row a restore brought and the repair took the self role off. It cannot be
// deleted and its confirmation cannot be cleared — the restored op log is
// signed by that device_id, and signatureVerificationKeys() is confirmed-only,
// so a rebuild would quarantine the whole ledger. It is also not a device: it
// is a machine that is gone, and the demotion put it in front of every reader
// that lists peers. The stamp is what separates those two readings.

function aRetiredRowUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('retired-row-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    foreach (['identity', 'gdk'] as $directory) {
        foreach ((array) glob(UserDataPathService::appPath("sync/{$directory}/{$user->id}.enc*")) as $stale) {
            @unlink((string) $stale);
        }
    }

    return $user;
}

// The restored state, then the repair: a real mint for the row the backup's
// database held, the key-file removed because a backup may never carry one,
// and the explicit action the settings screen offers.
function aRepairedRestoredDevice(DatabaseManager $db, User $user, Session $session): string
{
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);

    $restoredDeviceId = $identityService->generateAndPersist((int) $user->id, $session)->deviceId;

    @unlink(UserDataPathService::appPath("sync/identity/{$user->id}.enc"));

    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-09-01T10:00:00Z',
        'updated_at' => '2026-09-01T10:00:00Z',
    ]);

    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));

    test()->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('repairRestoredSyncIdentity')
        ->assertSet('syncEnabled', true);

    return $restoredDeviceId;
}

it('keeps the retired row out of every reader that lists devices', function (): void {
    $user = aRetiredRowUser('retired-row-hidden');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);
    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $restoredDeviceId = aRepairedRestoredDevice($db, $user, $session);

    $listed = array_map(
        static fn (object $row): string => is_string($row->device_id) ? $row->device_id : '',
        $registry->confirmedDevices((int) $user->id),
    );

    expect($listed)->not->toContain($restoredDeviceId)
        ->and($registry->otherDeviceNames((int) $user->id))->not->toHaveKey($restoredDeviceId)
        // An undeliverable wrap in the mailbox forever, for a machine that will
        // never collect it. The demotion is what made it eligible at all.
        ->and($rotation->peersOwedEpochs((int) $user->id))->toBe([]);
});

// The load-bearing half. Clearing confirmed_at would have hidden the row from
// all the same readers and taken the restored history down with it.
it('goes on verifying the history the retired machine signed', function (): void {
    $user = aRetiredRowUser('retired-row-verifies');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    $restoredDeviceId = aRepairedRestoredDevice($db, $user, $session);

    $row = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $restoredDeviceId)
        ->first();

    expect($registry->retainedDeviceKeys((int) $user->id))->toHaveKey($restoredDeviceId)
        ->and($registry->signatureVerificationKeys((int) $user->id))->toHaveKey($restoredDeviceId)
        ->and($registry->authorIdsWithAKeyOnFile((int) $user->id))->toContain($restoredDeviceId)
        ->and($row)->not->toBeNull()
        ->and($row->confirmed_at)->not->toBeNull()
        ->and($row->self_retired_at)->not->toBeNull()
        ->and((int) $row->is_self)->toBe(0);
});

it('does not render the retired machine on the devices screen', function (): void {
    $user = aRetiredRowUser('retired-row-screen');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    aRepairedRestoredDevice($db, $user, $session);

    $devices = Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('syncEnabled', true)
        ->assertSet('registeredWithoutIdentity', false)
        ->get('devices');

    expect($devices)->toHaveCount(1)
        ->and($devices[0]['is_self'])->toBeTrue();
});

// The negative control: only a row the repair stamped is hidden. A peer this
// reader paired with and has not removed is still a device, and a marker that
// swallowed those would empty the list it exists to tidy.
it('leaves an ordinary paired peer on the list', function (): void {
    $user = aRetiredRowUser('retired-row-peer-kept');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    aRepairedRestoredDevice($db, $user, $session);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'a-peer-still-standing',
        'name' => 'The other one',
        'ed25519_public_key_hex' => str_repeat('ab', 32),
        'x25519_public_key_hex' => str_repeat('cd', 32),
        'safety_number_words' => 'abandon ability able about above absent',
        'is_self' => 0,
        'self_retired_at' => null,
        'paired_at' => '2026-09-02T10:00:00Z',
        'confirmed_at' => '2026-09-02T10:05:00Z',
        'last_seen_at' => null,
        'created_at' => '2026-09-02T10:00:00Z',
        'updated_at' => '2026-09-02T10:00:00Z',
    ]);

    expect($registry->otherDeviceNames((int) $user->id))->toBe(['a-peer-still-standing' => 'The other one'])
        ->and($registry->confirmedDevices((int) $user->id))->toHaveCount(2);
});
