<?php

declare(strict_types=1);

// The shared new-PIN guard runs ahead of changePin() and resetForgottenPin():
// an invalid PIN must flash and return before any provisioner work happens.

use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
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
