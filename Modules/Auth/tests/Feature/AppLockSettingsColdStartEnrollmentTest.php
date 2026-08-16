<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Auth\Internal\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Core\Models\User;

/*
 * Enrolling biometric unlock where the OS owns the biometric.
 *
 * WebAuthn is a browser API, and the desktop shell has no platform
 * authenticator behind it — navigator.credentials.create() resolved to nothing
 * and the button read as broken. So when a cold-start vault reports itself
 * available, the section enrolls against the OS directly and never dispatches
 * the browser event.
 *
 * Enrolling stores the LIVE data key under the OS gate, which is why it only
 * works while unlocked, and why every refusal below has to say something
 * specific: a user staring at a dead button cannot tell "locked" from "your
 * device said no".
 *
 * The vault is a mock rather than a platform implementation: the contract is
 * the seam, and the real ones are exercised in their own module tests.
 */

function coldStartSettingsUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('settings-pass'),
        'period_start_day' => 1,
    ]);
}

/**
 * @param  bool  $available  Whether the OS gate reports itself usable.
 * @param  bool  $enrolls  Whether the OS accepts the key when handed it.
 */
function bindColdStartVault(bool $available, bool $enrolls = true, bool $isEnrolled = false): MockInterface
{
    $vault = Mockery::mock(ColdStartVault::class);
    $vault->shouldReceive('isAvailable')->andReturn($available);
    $vault->shouldReceive('isEnrolled')->andReturn($isEnrolled);
    $vault->shouldReceive('enroll')->andReturn($enrolls);

    app()->instance(ColdStartVault::class, $vault);

    return $vault;
}

it('offers biometric unlock when the OS gate is available, without a browser check', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-capable'));
    bindColdStartVault(available: true, isEnrolled: true);

    Livewire::test(AppLockSettingsSection::class)
        ->assertSet('biometricCapable', true)
        ->assertSet('biometricEnrolled', true);
});

it('does not offer it when no cold-start gate exists', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-incapable'));
    bindColdStartVault(available: false);

    Livewire::test(AppLockSettingsSection::class)
        ->assertSet('biometricCapable', false)
        ->assertSet('biometricEnrolled', false);
});

it('enrolls against the OS instead of dispatching the WebAuthn event', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-enrolls'));
    bindColdStartVault(available: true);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->call('startEnroll')
        ->assertNotDispatched('beatrax:webauthn-create')
        ->assertSet('biometricEnrolled', true)
        ->assertSet('flashMessage', '');
});

// The key is only in the session while the app is unlocked. Enrolling from a
// locked session has nothing to store, and has to say which of the two it is.
it('says the app is locked when there is no live key to store', function (): void {
    $user = coldStartSettingsUser('cold-locked');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');
    bindColdStartVault(available: true);

    Livewire::test(AppLockSettingsSection::class)
        ->set('lockEnabled', true)
        ->call('startEnroll')
        ->assertSet('biometricEnrolled', false)
        ->assertSee('Unlock the app before enrolling.');
});

it('reports a device that declines to store the key', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-declines'));
    bindColdStartVault(available: true, enrolls: false);

    Livewire::test(AppLockSettingsSection::class)
        ->set('newPin', '123456')
        ->set('confirmPin', '123456')
        ->set('accountPassword', 'settings-pass')
        ->call('setPin')
        ->call('startEnroll')
        ->assertSet('biometricEnrolled', false)
        ->assertSee('Your device declined to store the key.');
});

// Inside the desktop shell with no OS gate, dispatching the browser event
// resolves to nothing — so say it is unsupported rather than appear to hang.
it('refuses in the desktop shell rather than dispatching into nothing', function (): void {
    $user = coldStartSettingsUser('cold-shell');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');
    bindColdStartVault(available: false);
    app(ConfigRepository::class)->set('nativephp-internal.running', true);

    Livewire::test(AppLockSettingsSection::class)
        ->set('lockEnabled', true)
        ->call('startEnroll')
        ->assertNotDispatched('beatrax:webauthn-create')
        ->assertSee('Biometric unlock is not available on this device.');
});

it('still asks the browser when there is no shell and no OS gate', function (): void {
    $user = coldStartSettingsUser('cold-browser');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');
    bindColdStartVault(available: false);

    Livewire::test(AppLockSettingsSection::class)
        ->set('lockEnabled', true)
        ->call('startEnroll')
        ->assertDispatched('beatrax:webauthn-create');
});

it('will not enroll before a PIN lock exists', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-no-lock'));
    bindColdStartVault(available: true);

    Livewire::test(AppLockSettingsSection::class)
        ->call('startEnroll')
        ->assertSet('biometricEnrolled', false)
        ->assertSee('Enable the PIN lock first before enrolling biometrics.');
});

// De-enrolling has to clear the OS entry too, or the key stays recoverable
// under a biometric the settings screen now says is off.
it('clears the OS entry when de-enrolling with the correct PIN', function (): void {
    $user = coldStartSettingsUser('cold-deenrolls');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');

    $vault = bindColdStartVault(available: true);
    $vault->shouldReceive('forget')->once()->with($user->id);

    Livewire::test(AppLockSettingsSection::class)
        ->set('deenrollPin', '123456')
        ->call('deenroll')
        ->assertSet('biometricEnrolled', false)
        ->assertSet('confirmingDeenroll', false);
});

it('leaves the OS entry alone when the de-enroll PIN is wrong', function (): void {
    $user = coldStartSettingsUser('cold-wrong-pin');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');

    $vault = bindColdStartVault(available: true, isEnrolled: true);
    $vault->shouldNotReceive('forget');

    Livewire::test(AppLockSettingsSection::class)
        ->set('deenrollPin', '000000')
        ->call('deenroll')
        ->assertSet('biometricEnrolled', true);
});
