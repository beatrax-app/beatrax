<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

function armingUser(string $username): User
{
    return User::query()->forceCreate([
        'username' => $username,
        'password' => bcrypt('settings-pass'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The layout emits window.beatraxIdleMs only when the lock was already on at
// render, and setPin() neither redirects nor reloads. The store's init() has
// already run and already returned, so the veil, the /lock/background marker
// and the idle watch stayed unarmed until the reader happened to reload.
it('sends the idle window with the event that says a lock now exists', function (): void {
    $this->actingAs(armingUser('arms-itself'));

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'settings-pass')
        ->call('setPin', '123456', '123456')
        ->assertHasNoErrors()
        ->assertDispatched('app-lock-configured', ms: 5 * 60 * 1000);
});

// Veil and grace are lock machinery and are rightly withheld from a reader who
// has no lock. The two WebAuthn listeners are the opposite -- they are the way
// in -- and they sat below the same guard, on a lock screen whose layout never
// emits beatraxIdleMs, so the button dispatched into a page listening for it.
it('registers the biometric listeners before the lock-enabled guard', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/lock.js'));

    $arm = strpos($source, '_armBiometricListeners();');
    $guard = strpos($source, 'if (!_lockEnabled()) {');

    expect($arm)->not->toBeFalse()
        ->and($guard)->not->toBeFalse()
        ->and($arm)->toBeLessThan($guard);
});

// The machinery is reachable twice now -- from init() on a page that already
// had a lock, and from the event above when the reader has just set one.
it('refuses to arm the lock machinery twice', function (): void {
    $source = (string) file_get_contents(base_path('resources/js/lock.js'));

    expect($source)->toContain('_armed: false')
        ->and($source)->toContain("document.addEventListener('app-lock-configured'");
});
