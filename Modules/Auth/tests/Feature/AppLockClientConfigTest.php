<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\IdleTimeoutOptions;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Models\User;

// The layout emits window.beatraxIdleMs only for a lock-enabled user, and from
// that user's own idle_timeout_minutes rather than one constant for everybody.
// window.beatraxGraceMs travels beside it and is the same for everybody: the
// window leaving the foreground locks on is not the idle timeout, and lock.js
// reads it from here rather than keeping a second copy the settings copy that
// discloses it could drift from.

function clientConfigUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('client-config-pass'),
        'period_start_day' => 1,
    ]);
}

it('idleTimeoutMs returns null when the user has no lock config row', function (): void {
    $user = clientConfigUser('cc-no-row');

    /** @var AppLockClientConfig $config */
    $config = $this->app->make(AppLockClientConfig::class);

    expect($config->idleTimeoutMs($user->id))->toBeNull();
});

it('idleTimeoutMs returns null when the lock is disabled', function (): void {
    $user = clientConfigUser('cc-disabled');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => false,
        'idle_timeout_minutes' => 15,
        'failed_attempts' => 0,
    ]);

    /** @var AppLockClientConfig $config */
    $config = $this->app->make(AppLockClientConfig::class);

    expect($config->idleTimeoutMs($user->id))->toBeNull();
});

it('idleTimeoutMs returns the per-user preset in milliseconds when the lock is enabled', function (): void {
    $user = clientConfigUser('cc-enabled');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'client-config-pass');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['idle_timeout_minutes' => 15]);

    /** @var AppLockClientConfig $config */
    $config = $this->app->make(AppLockClientConfig::class);

    expect($config->idleTimeoutMs($user->id))->toBe(15 * 60_000);
});

it('layout does not emit beatraxIdleMs for a user without the lock enabled', function (): void {
    $user = clientConfigUser('cc-layout-off');
    $this->actingAs($user);

    $this->get('/help/data-locations')
        ->assertOk()
        ->assertDontSee('window.beatraxIdleMs', false);
});

it('layout emits the configured idle timeout for a lock-enabled user', function (): void {
    $user = clientConfigUser('cc-layout-on');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'client-config-pass');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['idle_timeout_minutes' => 30]);

    $this->get('/help/data-locations')
        ->assertOk()
        ->assertSee('window.beatraxIdleMs = '.(30 * 60_000).';', false);
});

it('backgroundGraceMs answers the disclosed window, whoever asks', function (): void {
    /** @var AppLockClientConfig $config */
    $config = $this->app->make(AppLockClientConfig::class);

    expect($config->backgroundGraceMs())->toBe(IdleTimeoutOptions::BACKGROUND_GRACE_SECONDS * 1000);
});

it('layout hands the grace window to the browser beside the idle timeout', function (): void {
    $user = clientConfigUser('cc-layout-grace');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '123456', 'client-config-pass');

    $this->get('/help/data-locations')
        ->assertOk()
        ->assertSee('window.beatraxGraceMs = '.(IdleTimeoutOptions::BACKGROUND_GRACE_SECONDS * 1000).';', false);
});

it('layout emits no grace window for a user without the lock enabled', function (): void {
    $user = clientConfigUser('cc-layout-grace-off');
    $this->actingAs($user);

    $this->get('/help/data-locations')
        ->assertOk()
        ->assertDontSee('window.beatraxGraceMs', false);
});
