<?php

declare(strict_types=1);

// Code-review fix CR-03 — per-user, lock-gated window.beatraxIdleMs emission.

use Illuminate\Database\DatabaseManager;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Models\User;

/*
 * The authenticated layout must emit window.beatraxIdleMs ONLY when the app
 * lock is enabled for the current user, and with the user's configured
 * idle_timeout_minutes — never a hardcoded constant for every user (CR-03).
 */

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
