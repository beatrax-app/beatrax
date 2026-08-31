<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockCredentialRejections;
use Modules\Auth\Internal\Lock\AppLockPinShape;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// The unlock screen is a numeric keypad with no letter key on it, so a PIN the
// write path accepts but that keypad cannot type is a permanent lockout with
// only supported UI. The length floor alone let "abcdef" through.

function digitsOnlyUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'account-password',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('rejects a PIN that is not digits', function (string $pin): void {
    /** @var AppLockCredentialRejections $rejections */
    $rejections = app(AppLockCredentialRejections::class);

    expect($rejections->newPin($pin, $pin))->toBe(Lang::get('auth::app_lock.error_pin_digits', ['min' => AppLockPinShape::MINIMUM_LENGTH, 'max' => AppLockPinShape::MAXIMUM_LENGTH]));
})->with(['abcdef', 'abc123', '12345 6', '２４６８１０', '246810.', '-246810']);

it('rejects a PIN longer than the keypad can hold', function (): void {
    /** @var AppLockCredentialRejections $rejections */
    $rejections = app(AppLockCredentialRejections::class);

    expect($rejections->newPin('12345678901', '12345678901'))
        ->toBe(Lang::get('auth::app_lock.error_pin_digits', ['min' => AppLockPinShape::MINIMUM_LENGTH, 'max' => AppLockPinShape::MAXIMUM_LENGTH]));
});

it('still accepts a six-to-ten digit PIN', function (string $pin): void {
    /** @var AppLockCredentialRejections $rejections */
    $rejections = app(AppLockCredentialRejections::class);

    expect($rejections->newPin($pin, $pin))->toBeNull();
})->with(['246810', '1234567890']);

it('keeps the short-PIN and mismatch messages for their own cases', function (): void {
    /** @var AppLockCredentialRejections $rejections */
    $rejections = app(AppLockCredentialRejections::class);

    expect($rejections->newPin('12345', '12345'))->toBe(Lang::get('auth::app_lock.error_pin_too_short'))
        ->and($rejections->newPin('246810', '135791'))->toBe(Lang::get('auth::app_lock.error_pin_mismatch'));
});

it('refuses to enable the lock from the settings screen with a lettered PIN', function (): void {
    $user = digitsOnlyUser('pin-digits-settings');
    test()->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', 'abcdef')
        ->set('confirmPin', 'abcdef')
        ->set('accountPassword', 'account-password')
        ->call('setPin')
        ->assertSet('lockEnabled', false)
        ->assertSee(Lang::get('auth::app_lock.error_pin_digits', ['min' => AppLockPinShape::MINIMUM_LENGTH, 'max' => AppLockPinShape::MAXIMUM_LENGTH]));
});

it('refuses to reset a forgotten PIN to a lettered one', function (): void {
    $user = digitsOnlyUser('pin-digits-forgot');
    test()->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable($user->id, '246810', 'account-password');

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', 'abcdefg')
        ->set('confirmPin', 'abcdefg')
        ->set('accountPassword', 'account-password')
        ->call('resetForgottenPin')
        ->assertSee(Lang::get('auth::app_lock.error_pin_digits', ['min' => AppLockPinShape::MINIMUM_LENGTH, 'max' => AppLockPinShape::MAXIMUM_LENGTH]));

    // The PIN that was already there still opens the lock.
    expect($provisioner->changePin($user->id, '246810', '135791'))->toBeTrue();
});

it('refuses a lettered PIN in the provisioner and writes nothing', function (): void {
    $user = digitsOnlyUser('pin-digits-provisioner');
    test()->actingAs($user);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);

    expect(fn () => $provisioner->enable($user->id, 'abcdef', 'account-password'))
        ->toThrow(ValidationException::class);

    expect(app(DatabaseManager::class)->connection()
        ->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->exists())->toBeFalse();
});
