<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Core\Models\User;

/*
 * Feature tests for the App Lock settings section (plan 05-04).
 *
 * These tests go GREEN when AppLockSettingsSection is shipped with the
 * correct enable/disable/idle-timeout flows and D-23 confirmation logic.
 */

function settingsSectionUser(string $username = 'settings-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('settings-pass'),
        'period_start_day' => 1,
    ]);
}

it('AppLockSettingsSection component is registered and mounts for an authenticated user', function (): void {
    $user = settingsSectionUser();
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->assertStatus(200);
});

it('user with no lock can enable it by setting a valid PIN with matching confirmation and account password', function (): void {
    $user = settingsSectionUser('enable-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    expect($provisioner->isEnabled($user->id))->toBeFalse();

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->assertHasNoErrors();

    expect($provisioner->isEnabled($user->id))->toBeTrue();
});

it('rejects a PIN shorter than 4 digits with the correct error copy', function (): void {
    $user = settingsSectionUser('short-pin-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123')
        ->set('confirmPin', '123')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->assertSee('PIN must be at least 4 digits.');

    expect($provisioner->isEnabled($user->id))->toBeFalse();
});

it('rejects mismatched PIN confirmation with the correct error copy', function (): void {
    $user = settingsSectionUser('mismatch-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '1234')
        ->set('confirmPin', '5678')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->assertSee("PINs don't match. Try again.");

    expect($provisioner->isEnabled($user->id))->toBeFalse();
});

it('changing the idle timeout preset persists without requiring PIN confirmation', function (): void {
    $user = settingsSectionUser('idle-user');
    $this->actingAs($user);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    Livewire::test(AppLockSettingsSection::class)
        ->set('idleTimeoutMinutes', 15)
        ->call('setIdleTimeout')
        ->assertHasNoErrors();

    $row = $db->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first(['idle_timeout_minutes']);

    expect($row)->not->toBeNull();
    /** @var stdClass $row */
    expect((int) $row->idle_timeout_minutes)->toBe(15);
});

it('disabling the lock requires the correct PIN — wrong PIN keeps lock enabled', function (): void {
    $user = settingsSectionUser('disable-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    // Enable the lock first.
    $provisioner->enable($user->id, '4321', 'settings-pass');
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Wrong PIN: lock stays enabled.
    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', 'wrong')
        ->call('disable')
        ->assertSee('Incorrect PIN.');

    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Correct PIN: lock disabled.
    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '4321')
        ->call('disable')
        ->assertHasNoErrors();

    expect($provisioner->isEnabled($user->id))->toBeFalse();
});
