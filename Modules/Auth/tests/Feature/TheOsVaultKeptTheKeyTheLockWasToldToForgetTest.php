<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Livewire\Livewire;
use Modules\Auth\Internal\Http\Livewire\LockScreen;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Auth\Tests\Support\DurableColdStartVault;
use Modules\Core\Models\User;
use Modules\Core\Public\Support\Lang;

// enable() already deletes the WebAuthn enrolments, because "a leftover
// enrollment wraps whatever key the previous provisioning held". The OS vault
// holds the same thing and neither enable() nor disable() touched it: on the
// desktop, whose isEnrolled() answers from a file on disk, turning the app lock
// off left a safeStorage copy of the data key behind, and turning it back on
// found that file still there -- so the lock screen never re-enrolled and Touch
// ID unlock never came back, with nothing on screen to say why.

function vaultKeptUser(string $username): User
{
    /** @var User $user */
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('vault-account-pass'),
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);

    return $user;
}

function vaultKeptEnroll(string $pin): void
{
    Livewire::test(AppLockSettingsSection::class)
        ->call('startEnroll')
        ->call('enrollWithPin', $pin)
        ->assertSet('biometricEnrolled', true);
}

function vaultKeptEnableAndEnroll(string $pin): void
{
    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'vault-account-pass')
        ->call('setPin', $pin, $pin)
        ->assertSet('lockEnabled', true);

    vaultKeptEnroll($pin);
}

it('drops the OS-vault copy of the data key when the lock is turned off', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-disable');
    vaultKeptEnableAndEnroll('135790');

    expect($vault->isEnrolled($user->id))->toBeTrue('the fixture must have enrolled, or the assertion below proves nothing');

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmDisable')
        ->call('disable', '135790')
        ->assertSet('lockEnabled', false);

    expect($vault->keys)->toBe([], 'disable() clears every durable wrap of the data key, and the OS vault holds one');
});

it('leaves the native unlock off after the lock is turned off and back on, until it is asked for again', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-recycle');
    vaultKeptEnableAndEnroll('135790');

    $firstKey = $vault->keys[$user->id];

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmDisable')
        ->call('disable', '135790')
        ->assertSet('lockEnabled', false);

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'vault-account-pass')
        ->call('setPin', '246802', '246802')
        ->assertSet('lockEnabled', true);

    /** @var Session $session */
    $session = $this->app->make(Session::class);
    $this->app->make(AppLockKeyService::class)->withhold($session);

    Livewire::test(LockScreen::class)->call('submit', '246802');

    // The unlock arms nothing. Enrolling is a thing the reader asks for on the
    // settings screen, and turning the lock off and on again is not that ask.
    expect($vault->keys[$user->id] ?? null)
        ->toBeNull('a correct PIN is proof of identity, not a request to enrol a fingerprint')
        ->and($firstKey)->toBeString('the case is only worth anything if something was enrolled to begin with');

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', false);

    // And the ask still works: the same settings control arms it again, with
    // the key the new PIN provisioned rather than the one it replaced.
    vaultKeptEnroll('246802');

    expect($vault->keys[$user->id] ?? null)
        ->toBeString('the settings control is the way back')
        ->not->toBe($firstKey, 'the new blob must wrap the key the new PIN provisioned');

    Livewire::test(LockScreen::class)->assertSet('nativeUnlockAvailable', true);
});

// The settings screen is not the only way in. The mobile first-run import
// provisions the lock through MobileLockGateway, which carried no forget of its
// own and was safe only while the mobile vault happened to answer isEnrolled()
// from the very column enable() resets.
it('drops the OS-vault copy of the data key when the mobile import path enables the lock', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-mobile-import');
    $carriedOver = random_bytes(32);
    $vault->enroll($user->id, $carriedOver);

    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $this->app->make(MobileLockGateway::class)
        ->enableAppLock($user->id, '135790', 'vault-account-pass', $session);

    expect($vault->keys)->toBe([], 'enable() mints a key the enrolment predating it cannot wrap, whichever caller ran it');
});

// Removing the enrolment reported the same clean screen whether the key went
// or not: the toggle flipped off, the message stayed empty, and a wrapped data
// key the reader had just asked to be rid of was still in the OS vault.
it('says so when the OS would not release the key it was told to forget', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-deenroll-refused');
    vaultKeptEnableAndEnroll('135790');

    $vault->refuseForget = true;

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmDeenroll')
        ->call('deenroll', '135790')
        ->assertSet('flashMessage', Lang::get('auth::app_lock.error_vault_kept_key'))
        ->assertSet('biometricEnrolled', true);

    expect($vault->keys[$user->id] ?? null)->not->toBeNull('the fixture must leave the key behind, or the assertions above prove nothing');
});

it('clears the screen when the key actually went', function (): void {
    $vault = new DurableColdStartVault;
    $this->app->instance(ColdStartVault::class, $vault);

    $user = vaultKeptUser('vault-deenroll-ok');
    vaultKeptEnableAndEnroll('135790');

    Livewire::test(AppLockSettingsSection::class)
        ->call('confirmDeenroll')
        ->call('deenroll', '135790')
        ->assertSet('flashMessage', '')
        ->assertSet('biometricEnrolled', false);

    expect($vault->keys)->toBe([]);
});
