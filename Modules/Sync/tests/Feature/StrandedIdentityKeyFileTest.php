<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Sync\Internal\Exceptions\DeviceIdentityUnreadableException;
use Modules\Sync\Internal\Identity\DeviceIdentityLoader;
use Modules\Sync\Internal\Identity\DeviceIdentityService;
use Modules\Sync\Internal\Identity\DeviceIdentityState;
use Modules\Sync\Public\Http\Livewire\DevicesAndSyncSettingsSection;

uses(RefreshDatabase::class);

// The key-file lives on the filesystem and the key that opens it lives in the
// database, so a wiped or restored database leaves one encrypted under a KEK
// nothing can produce again. Every render of Data & Devices then died inside
// BackupEncryptor::decrypt, and pairing is reached through that page.

const STRANDED_IDENTITY_FOREIGN_KEK = "\x7f";

function strandedIdentityUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('stranded-identity-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    // RefreshDatabase resets the database but not on-disk files, and user ids
    // are reused across runs, so an earlier run's key-file — and any retired
    // sibling of it — can still be sitting there.
    foreach (['identity', 'gdk'] as $directory) {
        foreach ((array) glob(UserDataPathService::appPath("sync/{$directory}/{$user->id}.enc*")) as $stale) {
            @unlink((string) $stale);
        }
    }

    return $user;
}

function strandedIdentityPath(User $user): string
{
    return UserDataPathService::appPath("sync/identity/{$user->id}.enc");
}

it('renders the settings section, and leaves the file alone, when the key-file does not open', function (): void {
    $user = strandedIdentityUser('stranded-identity-render');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);

    $identityService->generateAndPersist((int) $user->id, $session);
    $bytes = (string) file_get_contents(strandedIdentityPath($user));

    // The state a fresh app-lock leaves: a structurally valid key that opens
    // nothing, exactly as a re-provisioned data key does.
    AppLockTestHarness::unlock($session, str_repeat(STRANDED_IDENTITY_FOREIGN_KEK, 32));

    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertStatus(200)
        ->assertSet('identityUnreadable', true);

    // A page load must never destroy key material: the KEK that opens this
    // file can still come back with the database that wraps it.
    expect(file_get_contents(strandedIdentityPath($user)))->toBe($bytes);
});

it('separates a key-file that will not open from one that was never written', function (): void {
    $user = strandedIdentityUser('stranded-identity-state');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);

    $identityService->generateAndPersist((int) $user->id, $session);
    AppLockTestHarness::unlock($session, str_repeat(STRANDED_IDENTITY_FOREIGN_KEK, 32));

    expect($loader->state((int) $user->id, $session))->toBe(DeviceIdentityState::Unreadable)
        ->and($loader->load((int) $user->id, $session))->toBeNull()
        ->and($loader->exists((int) $user->id))->toBeTrue();

    @unlink(strandedIdentityPath($user));

    expect($loader->state((int) $user->id, $session))->toBe(DeviceIdentityState::Absent);
});

it('refuses to mint a second identity over a key-file it cannot open', function (): void {
    $user = strandedIdentityUser('stranded-identity-nomint');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);

    $identityService->generateAndPersist((int) $user->id, $session);
    $bytes = (string) file_get_contents(strandedIdentityPath($user));

    AppLockTestHarness::unlock($session, str_repeat(STRANDED_IDENTITY_FOREIGN_KEK, 32));

    expect(fn () => $identityService->generateAndPersist((int) $user->id, $session))
        ->toThrow(DeviceIdentityUnreadableException::class);

    // Silently minting here is the destructive reading of "no identity": it
    // would write over the only copy of the keys the op-log is signed with.
    expect(file_get_contents(strandedIdentityPath($user)))->toBe($bytes);
});

it('retires the unreadable key-file aside and mints a new one on the explicit action', function (): void {
    $user = strandedIdentityUser('stranded-identity-replace');

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var DeviceIdentityService $identityService */
    $identityService = $this->app->make(DeviceIdentityService::class);
    /** @var DeviceIdentityLoader $loader */
    $loader = $this->app->make(DeviceIdentityLoader::class);

    $identityService->generateAndPersist((int) $user->id, $session);
    $bytes = (string) file_get_contents(strandedIdentityPath($user));

    // The wiped database this state comes from: the self row is gone with it,
    // and the app lock has been set up again under a new data key.
    $db->connection()->table('device_registry')->where('user_id', $user->id)->delete();
    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => 1,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'created_at' => '2026-08-21T10:00:00Z',
        'updated_at' => '2026-08-21T10:00:00Z',
    ]);
    AppLockTestHarness::unlock($session, str_repeat(STRANDED_IDENTITY_FOREIGN_KEK, 32));

    $this->actingAs($user);

    Livewire::test(DevicesAndSyncSettingsSection::class)
        ->assertSet('identityUnreadable', true)
        ->call('replaceUnreadableIdentity')
        ->assertSet('identityUnreadable', false)
        ->assertSet('syncEnabled', true);

    $retired = (array) glob(strandedIdentityPath($user).'.unreadable-*');

    expect($retired)->toHaveCount(1)
        ->and(file_get_contents((string) $retired[0]))->toBe($bytes)
        ->and(file_get_contents(strandedIdentityPath($user)))->not->toBe($bytes)
        ->and($loader->state((int) $user->id, $session))->toBe(DeviceIdentityState::Usable);
});
