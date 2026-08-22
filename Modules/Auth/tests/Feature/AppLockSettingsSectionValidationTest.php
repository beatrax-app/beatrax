<?php

declare(strict_types=1);

// The shared new-PIN guard runs ahead of changePin() and resetForgottenPin():
// an invalid PIN must flash and return before any provisioner work happens.

use Livewire\Livewire;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;

function settingsValidationUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('settings-pass'),
        'period_start_day' => 1,
    ]);
}

it('changePin flashes the too-short copy and bails before touching the provisioner', function (): void {
    $user = settingsValidationUser('changepin-short');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '123456')
        ->set('newPin', '12')
        ->set('confirmPin', '12')
        ->call('changePin')
        ->assertSet('flashMessage', 'PIN must be at least 6 digits.')
        ->assertSet('changePinSuccessMessage', '');
});

it('changePin flashes the mismatch copy when the confirmation differs', function (): void {
    $user = settingsValidationUser('changepin-mismatch');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '123456')
        ->set('newPin', '432100')
        ->set('confirmPin', '999999')
        ->call('changePin')
        ->assertSet('flashMessage', "PINs don't match. Try again.");
});

it('resetForgottenPin flashes the validation copy before checking the password', function (): void {
    $user = settingsValidationUser('forgotpin-short');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'settings-pass')
        ->set('newPin', '12')
        ->set('confirmPin', '12')
        ->call('resetForgottenPin')
        ->assertSet('flashMessage', 'PIN must be at least 6 digits.');
});

// A box left empty and a box filled in wrongly are different states, and the
// screen said "Incorrect account password." for both. A reader who typed
// nothing is then sent to a password manager instead of to the field in front
// of them. Every confirmation box on this screen conflated the two.
it('setPin names the empty account-password box rather than calling it wrong', function (): void {
    $user = settingsValidationUser('setpin-blank-password');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->set('accountPassword', '')
        ->call('setPin')
        ->assertSet('flashMessage', 'Enter your account password.')
        ->assertSet('lockEnabled', false);
});

it('setPin still calls a filled-in account password wrong when it is', function (): void {
    $user = settingsValidationUser('setpin-wrong-password');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->set('accountPassword', 'not-the-password')
        ->call('setPin')
        ->assertSet('flashMessage', 'Incorrect account password.')
        ->assertSet('lockEnabled', false);
});

it('resetForgottenPin names the empty account-password box rather than calling it wrong', function (): void {
    $user = settingsValidationUser('forgotpin-blank-password');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->set('accountPassword', '')
        ->call('resetForgottenPin')
        ->assertSet('flashMessage', 'Enter your account password.');
});

it('disable names the empty PIN box rather than calling it incorrect', function (): void {
    $user = settingsValidationUser('disable-blank-pin');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '')
        ->call('disable')
        ->assertSet('flashMessage', 'Enter your PIN.');
});

it('changePin names the empty current-PIN box rather than calling it incorrect', function (): void {
    $user = settingsValidationUser('changepin-blank-current');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('currentPin', '')
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->call('changePin')
        ->assertSet('flashMessage', 'Enter your PIN.');
});

it('deenroll names the empty PIN box rather than calling it incorrect', function (): void {
    $user = settingsValidationUser('deenroll-blank-pin');
    $this->actingAs($user);

    Livewire::test(AppLockSettingsSection::class)
        ->set('deenrollPin', '')
        ->call('deenroll')
        ->assertSet('flashMessage', 'Enter your PIN.');
});
