<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Listeners\SyncCaptureListener;
use Modules\Sync\Internal\OpLog\DeferredOpCaptureDrain;
use Modules\Sync\Public\Events\EntityMutated;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;
use Modules\Sync\Public\Services\PortableKeyMaterial;

uses(RefreshDatabase::class);

// A restore brings the whole database and the secret keys cannot travel with
// it, so the old machine's self row lands on a device holding no key-file. The
// settings screen read the row and said sync was on; the capture sink read the
// file and called this an install that had never synced. Between the two, every
// write the reader made was logged at debug and dropped — forever.

function aRestoredDeviceUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('restored-device-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // RefreshDatabase resets the database and not the filesystem, and user ids
    // are reused between runs, so an earlier run's key material can still be
    // sitting where this test is about to look for the absence of it.
    foreach (['identity', 'gdk'] as $directory) {
        foreach ((array) glob(UserDataPathService::appPath(sprintf('sync/%s/%s.enc*', $directory, $user->id))) as $stale) {
            @unlink((string) $stale);
        }
    }

    return $user;
}

function restoredDeviceIdentityPath(int $userId): string
{
    return UserDataPathService::appPath(sprintf('sync/identity/%s.enc', $userId));
}

function aRestoredAppLock(DatabaseManager $db, int $userId, Session $session): void
{
    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $userId,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-09-01T10:00:00Z',
        'updated_at' => '2026-09-01T10:00:00Z',
    ]);

    AppLockTestHarness::unlock($session, str_repeat("\x2a", 32));
}

// The state a restore leaves, built out of the real mint so the row carries a
// real device_id and real public halves: the self row the backup's database
// held, and the key-file the backup was never allowed to carry.
function aDatabaseRestoredOntoThisDevice(DatabaseManager $db, int $userId, Session $session): string
{
    /** @var DeviceIdentityService $identityService */
    $identityService = app(DeviceIdentityService::class);

    $identity = $identityService->generateAndPersist($userId, $session);

    @unlink(restoredDeviceIdentityPath($userId));
    aRestoredAppLock($db, $userId, $session);

    return $identity->deviceId;
}

function aRestoredSeries(DatabaseManager $db, int $userId): int
{
    return (int) $db->connection()->table('recurring_series')->insertGetId([
        'user_id' => $userId,
        'direction' => 'expense',
        'detected_name' => 'Streaming',
        'state' => 'approved',
        'cadence' => 'monthly',
        'latest_amount_minor' => -1099,
        'latest_currency' => 'EUR',
        'cluster_key' => 'restored-'.bin2hex(random_bytes(4)),
        'billing_day' => 7,
        'created_at' => '2026-09-01T00:00:00Z',
        'updated_at' => '2026-09-01T00:00:00Z',
    ]);
}

/**
 * @return array<string, mixed>
 */
function restoredSelfRows(DatabaseManager $db, int $userId): array
{
    /** @var array<string, mixed> $rows */
    $rows = $db->connection()->table('device_registry')
        ->where('user_id', $userId)
        ->where('is_self', 1)
        ->pluck('device_id', 'id')
        ->all();

    return $rows;
}

it('holds the coordinates of a write it cannot sign rather than dropping it', function (): void {
    $user = aRestoredDeviceUser('restored-holds-writes');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    aDatabaseRestoredOntoThisDevice($db, (int) $user->id, $session);
    $seriesId = aRestoredSeries($db, (int) $user->id);

    $this->actingAs($user);

    /** @var SyncCaptureListener $listener */
    $listener = $this->app->make(SyncCaptureListener::class);
    $listener->handleEntity(new EntityMutated('recurring_series', $seriesId, (int) $user->id, 'edit', [
        'billing_day' => 11,
    ]));

    $owed = $db->connection()->table('deferred_op_captures')
        ->where('user_id', $user->id)
        ->where('table_name', 'recurring_series')
        ->where('pk', (string) $seriesId)
        ->where('field', 'billing_day')
        ->exists();

    // The op log is empty either way — nothing here can sign one. What the
    // defect lost is the fact that this device owes the write at all.
    expect($owed)->toBeTrue()
        ->and($db->connection()->table('op_log_entries')->where('user_id', $user->id)->count())->toBe(0);
});

// The negative control, and the reason the queue is not simply always written:
// an install with neither half has no peer to owe, and switching sync on walks
// the whole database in one pass.
it('goes on dropping the writes of a device that never enabled sync', function (): void {
    $user = aRestoredDeviceUser('restored-never-synced');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $seriesId = aRestoredSeries($db, (int) $user->id);

    /** @var SyncCaptureListener $listener */
    $listener = $this->app->make(SyncCaptureListener::class);
    $listener->handleEntity(new EntityMutated('recurring_series', $seriesId, (int) $user->id, 'edit', [
        'billing_day' => 11,
    ]));

    expect($db->connection()->table('deferred_op_captures')->where('user_id', $user->id)->count())->toBe(0)
        ->and(restoredSelfRows($db, (int) $user->id))->toBe([]);
});

it('stops the screen claiming sync is enabled, and names what is wrong instead', function (): void {
    $user = aRestoredDeviceUser('restored-screen');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    aDatabaseRestoredOntoThisDevice($db, (int) $user->id, $session);

    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertStatus(200)
        ->assertSet('syncEnabled', false)
        ->assertSet('registeredWithoutIdentity', true)
        ->assertSet('identityUnreadable', false)
        ->assertSeeHtml('data-testid="registered-without-identity-notice"')
        ->assertSeeHtml('data-testid="repair-restored-sync-identity"');
});

it('refuses the ordinary enable while a restored self row stands', function (): void {
    $user = aRestoredDeviceUser('restored-refuses-enable');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $restoredDeviceId = aDatabaseRestoredOntoThisDevice($db, (int) $user->id, $session);

    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('enableSync')
        ->assertSet('syncEnabled', false);

    // Minting beside the restored row is the destructive reading of "sync is
    // off here": ten call sites take the first is_self row they find, so a
    // second one is a second identity to every one of them.
    expect(array_values(restoredSelfRows($db, (int) $user->id)))->toBe([$restoredDeviceId])
        ->and(file_exists(restoredDeviceIdentityPath((int) $user->id)))->toBeFalse();
});

it('mints one identity on the explicit repair and sends what the device was holding', function (): void {
    $user = aRestoredDeviceUser('restored-repair');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $restoredDeviceId = aDatabaseRestoredOntoThisDevice($db, (int) $user->id, $session);
    $seriesId = aRestoredSeries($db, (int) $user->id);

    $this->actingAs($user);

    /** @var SyncCaptureListener $listener */
    $listener = $this->app->make(SyncCaptureListener::class);
    $listener->handleEntity(new EntityMutated('recurring_series', $seriesId, (int) $user->id, 'edit', [
        'billing_day' => 11,
    ]));

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->call('repairRestoredSyncIdentity')
        ->assertSet('registeredWithoutIdentity', false)
        ->assertSet('syncEnabled', true)
        ->assertSet('flashMessage', 'This device has a new sync identity. Pair your other devices again.');

    $selfRows = array_values(restoredSelfRows($db, (int) $user->id));
    $retired = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('device_id', $restoredDeviceId)
        ->first();

    /** @var DeferredOpCaptureDrain $drain */
    $drain = $this->app->make(DeferredOpCaptureDrain::class);
    $drain->drain((int) $user->id);

    $signedBy = $db->connection()->table('op_log_entries')
        ->where('user_id', $user->id)
        ->where('table_name', 'recurring_series')
        ->where('pk', (string) $seriesId)
        ->pluck('device_id')
        ->unique()
        ->all();

    expect($selfRows)->toHaveCount(1)
        ->and($selfRows[0])->not->toBe($restoredDeviceId)
        // Retired out of the self role and no further: the restored history is
        // signed by this device_id, and signatureVerificationKeys() — what a
        // rebuild verifies against — is confirmed-only.
        ->and($retired)->not->toBeNull()
        ->and((int) $retired->is_self)->toBe(0)
        ->and($retired->confirmed_at)->not->toBeNull()
        ->and($signedBy)->toBe([$selfRows[0]])
        ->and($db->connection()->table('deferred_op_captures')->where('user_id', $user->id)->count())->toBe(0);
});

// A retirement the mint does not follow leaves a device owing nothing, which
// is the state this file exists to end — so the gate the enable refuses on is
// asked before anything is retired, not after.
it('retires nothing when the enable behind the repair cannot run', function (): void {
    $user = aRestoredDeviceUser('restored-no-app-lock');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    $restoredDeviceId = $identityService->generateAndPersist((int) $user->id, $session)->deviceId;
    @unlink(restoredDeviceIdentityPath((int) $user->id));

    $seriesId = aRestoredSeries($db, (int) $user->id);

    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('appLockConfigured', false)
        ->call('repairRestoredSyncIdentity')
        ->assertSet('registeredWithoutIdentity', true)
        ->assertSet('flashMessage', 'Set an app lock first to enable sync.');

    /** @var SyncCaptureListener $listener */
    $listener = $this->app->make(SyncCaptureListener::class);
    $listener->handleEntity(new EntityMutated('recurring_series', $seriesId, (int) $user->id, 'edit', [
        'billing_day' => 11,
    ]));

    expect(array_values(restoredSelfRows($db, (int) $user->id)))->toBe([$restoredDeviceId])
        ->and($db->connection()->table('deferred_op_captures')->where('user_id', $user->id)->count())->toBe(1);
});

// The premise the whole defect rests on, pinned against the symbol that names
// what travels rather than against an assumption about it.
it('leaves the identity key-file out of the files a backup carries', function (): void {
    $user = aRestoredDeviceUser('restored-carries-nothing');

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    app(DeviceIdentityService::class)->generateAndPersist((int) $user->id, $session);

    /** @var PortableKeyMaterial $portable */
    $portable = $this->app->make(PortableKeyMaterial::class);

    expect(file_exists(restoredDeviceIdentityPath((int) $user->id)))->toBeTrue()
        ->and($portable->keyrings())->not->toContain(restoredDeviceIdentityPath((int) $user->id));
});
