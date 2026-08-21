<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\SecretShield;

// navigator.credentials.create() resolves to nothing behind the desktop shell,
// which read as a dead button. Where a cold-start vault reports itself
// available, the section enrols against the OS instead.

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

// Stands in for the desktop keychain shield. Defined here rather than shared:
// Pest only loads the file it is asked to run, so a helper borrowed from a
// sibling test file makes this suite fail when run on its own.
function bindColdStartProtectingShield(): void
{
    app()->instance(SecretShield::class, new class implements SecretShield
    {
        public function protect(string $plaintext): string
        {
            return strrev($plaintext);
        }

        public function reveal(string $shielded): string
        {
            return strrev($shielded);
        }

        public function protectsAtRest(): bool
        {
            return true;
        }
    });
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

// The key is only in the session while unlocked, and a user staring at a dead
// button cannot tell "locked" from "your device said no".
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

// In the shell with no OS gate the browser event resolves to nothing, so this
// says unsupported rather than appearing to hang.
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
    // The browser path writes the wrap blob into the app's own SQLite file, so
    // it is only offered where the bound shield really protects those bytes.
    bindColdStartProtectingShield();

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

// De-enrolling must clear the OS entry too, or the key stays recoverable under
// a biometric the settings screen says is off.
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
