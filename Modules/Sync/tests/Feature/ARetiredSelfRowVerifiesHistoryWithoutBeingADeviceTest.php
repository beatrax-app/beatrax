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
        foreach ((array) glob(UserDataPathService::appPath(sprintf('sync/%s/%s.enc*', $directory, $user->id))) as $stale) {
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

    @unlink(UserDataPathService::appPath(sprintf('sync/identity/%s.enc', $user->id)));

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

// The transport half, which the demotion did not reach. Confirmed was all the
// Noise admission map asked for, so the row went on offering a static key a
// session could be opened on and an X25519 key an epoch could be wrapped to.
it('offers the retired machine no key a session or an epoch can be addressed to', function (): void {
    $user = aRetiredRowUser('retired-row-transport');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);

    $restoredDeviceId = aRepairedRestoredDevice($db, $user, $session);

    $db->connection()->table('device_registry')->insert([
        'user_id' => $user->id,
        'device_id' => 'a-peer-that-can-answer',
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

    $admitted = $registry->deviceX25519Keys((int) $user->id);

    expect(array_keys($admitted))->not->toContain($restoredDeviceId)
        // The negative control: the narrowing takes out the retired machine and
        // nothing else, or it would shut the transport to the whole household.
        ->and(array_keys($admitted))->toContain('a-peer-that-can-answer')
        ->and($registry->isStillConfirmed((int) $user->id, $restoredDeviceId))->toBeFalse()
        ->and($registry->isStillConfirmed((int) $user->id, 'a-peer-that-can-answer'))->toBeTrue();
});

// Removal's whole mechanism is clearing confirmed_at, and on this row that
// column is the history. The list never offers it, so the refusal has to be
// authoritative rather than drawn: a Livewire action is client-invokable.
it('refuses to remove the retired row, and leaves what verifies history standing', function (): void {
    $user = aRetiredRowUser('retired-row-unremovable');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);
    /** @var GdkRotationService $rotation */
    $rotation = $this->app->make(GdkRotationService::class);

    $restoredDeviceId = aRepairedRestoredDevice($db, $user, $session);

    $retiredRowId = (int) $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $restoredDeviceId)
        ->value('id');

    expect($retiredRowId)->toBeGreaterThan(0);

    expect(static fn () => $rotation->rotateAndRevoke((int) $user->id, $retiredRowId, $session))
        ->toThrow(InvalidArgumentException::class);

    $registry->purge((int) $user->id, $retiredRowId);

    expect($db->connection()->table('device_registry')->where('id', $retiredRowId)->value('confirmed_at'))
        ->not->toBeNull('a purge that reached this row would clear the confirmation a rebuild verifies the restored log against')
        ->and(array_keys($registry->signatureVerificationKeys((int) $user->id)))
        ->toContain($restoredDeviceId);
});

// The one undemotion. A key-file that opens for this row is the machine itself
// back, and a self row left stamped is a device hidden from its own list.
it('takes the stamp off again when a key-file answers for the row', function (): void {
    $user = aRetiredRowUser('retired-row-key-file-returns');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var DeviceRegistryService $registry */
    $registry = $this->app->make(DeviceRegistryService::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);

    $restoredDeviceId = $identityService->generateAndPersist((int) $user->id, $session)->deviceId;

    $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $restoredDeviceId)
        ->update(['is_self' => 0, 'self_retired_at' => '2026-09-12T09:00:00Z']);

    $identityService->generateAndPersist((int) $user->id, $session);

    $row = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $restoredDeviceId)
        ->first();

    expect($row)->not->toBeNull()
        ->and((int) $row->is_self)->toBe(1)
        ->and($row->self_retired_at)->toBeNull()
        ->and(array_keys($registry->deviceX25519Keys((int) $user->id)))->toContain($restoredDeviceId);
});
