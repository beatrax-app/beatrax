<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Core\Models\User;

/*
 * Feature tests for the App Lock settings section (plan 05-04).
 *
 * These tests go GREEN when AppLockSettingsSection is shipped with the
 * correct enable/disable/idle-timeout flows and D-23 confirmation logic.
 */

function appLockSettingsUser(string $username = 'settings-user'): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('settings-pass'),
        'period_start_day' => 1,
    ]);
}

it('AppLockSettingsSection component is registered and mounts for an authenticated user', function (): void {
    $user = appLockSettingsUser();
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->assertStatus(200);
});

it('user with no lock can enable it by setting a valid PIN with matching confirmation and account password', function (): void {
    $user = appLockSettingsUser('enable-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    expect($provisioner->isEnabled($user->id))->toBeFalse();

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->assertHasNoErrors()
        // Sibling app-lock-gated sections (Devices & Sync, D-02) refresh on this.
        ->assertDispatched('app-lock-configured');

    expect($provisioner->isEnabled($user->id))->toBeTrue();
});

it('rejects a PIN shorter than 6 digits with the correct error copy', function (): void {
    $user = appLockSettingsUser('short-pin-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123')
        ->set('confirmPin', '123')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->assertSee('PIN must be at least 6 digits.');

    expect($provisioner->isEnabled($user->id))->toBeFalse();
});

it('rejects mismatched PIN confirmation with the correct error copy', function (): void {
    $user = appLockSettingsUser('mismatch-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '654321')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->assertSee("PINs don't match. Try again.");

    expect($provisioner->isEnabled($user->id))->toBeFalse();
});

it('changing the idle timeout preset persists without requiring PIN confirmation', function (): void {
    $user = appLockSettingsUser('idle-user');
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

it('de-enrolling biometric keeps the lock enabled and both wrapped keys intact (CR-01)', function (): void {
    $user = appLockSettingsUser('deenroll-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);
    $provisioner->enable($user->id, '432100', 'settings-pass');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    // Enroll a biometric credential.
    /** @var BiometricDeviceStore $store */
    $store = $this->app->make(BiometricDeviceStore::class);
    $store->store($user->id, base64_encode('deenroll-cred'), 'Test Device', str_repeat("\xAA", 32), 'fake-cbor', 'webauthn');

    $before = $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first(['pin_hash', 'kdf_salt', 'pin_wrapped_key', 'password_wrapped_key']);
    expect($before)->not->toBeNull();

    // Wrong PIN: credentials stay, lock untouched.
    Livewire::test(AppLockSettingsSection::class)
        ->set('deenrollPin', '000000')
        ->call('deenroll')
        ->assertSee('Incorrect PIN.');

    expect($db->connection()->table('user_biometric_credentials')->where('user_id', $user->id)->count())->toBe(1);
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Correct PIN: only credentials deleted — lock stays enabled, key material intact.
    Livewire::test(AppLockSettingsSection::class)
        ->set('deenrollPin', '432100')
        ->call('deenroll')
        ->assertSet('biometricEnrolled', false);

    expect($db->connection()->table('user_biometric_credentials')->where('user_id', $user->id)->count())->toBe(0);
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    $after = $db->connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first(['pin_hash', 'kdf_salt', 'pin_wrapped_key', 'password_wrapped_key']);
    expect($after)->not->toBeNull();
    /** @var stdClass $before */
    /** @var stdClass $after */
    expect($after->pin_hash)->toBe($before->pin_hash);
    expect($after->kdf_salt)->toBe($before->kdf_salt);
    expect($after->pin_wrapped_key)->toBe($before->pin_wrapped_key);
    expect($after->password_wrapped_key)->toBe($before->password_wrapped_key);
});

it('disabling the lock requires the correct PIN — wrong PIN keeps lock enabled', function (): void {
    $user = appLockSettingsUser('disable-user');
    $this->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = $this->app->make(AppLockProvisioner::class);

    // Enable the lock first.
    $provisioner->enable($user->id, '432100', 'settings-pass');
    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Wrong PIN: lock stays enabled. IN-10: must be a NUMERIC wrong PIN —
    // a non-numeric value fails the #[Validate] regex so the property would
    // stay '' and the test would pass by coincidence (empty PIN also fails).
    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '000000')
        ->call('disable')
        ->assertSee('Incorrect PIN.');

    expect($provisioner->isEnabled($user->id))->toBeTrue();

    // Correct PIN: lock disabled.
    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '432100')
        ->call('disable')
        ->assertHasNoErrors();

    expect($provisioner->isEnabled($user->id))->toBeFalse();
});
