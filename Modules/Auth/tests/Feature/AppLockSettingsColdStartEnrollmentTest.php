<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Session\Session;
use Livewire\Livewire;
use Mockery\MockInterface;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Http\Livewire\AppLockSettingsSection;
use Modules\Auth\Public\Services\AppLockKeyService;
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
 * @param  bool|null  $enrolls  Whether the OS accepts the key when handed it, or null to
 *                              refuse the call outright — which is what "no PIN reached the
 *                              vault" has to be asserted as, since a vault that was never
 *                              asked and one that answered false read the same on screen.
 */
function bindColdStartVault(bool $available, ?bool $enrolls = true, bool $isEnrolled = false): MockInterface
{
    $vault = Mockery::mock(ColdStartVault::class);
    $vault->shouldReceive('isAvailable')->andReturn($available);
    $vault->shouldReceive('isEnrolled')->andReturn($isEnrolled);

    if ($enrolls === null) {
        $vault->shouldReceive('enroll')->never();
    } else {
        $vault->shouldReceive('enroll')->andReturn($enrolls);
    }

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

it('asks for the PIN instead of dispatching the WebAuthn event, and enrols once it has one', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-enrolls'));
    // setPin() provisions a fresh data key, so it clears the OS entry on its
    // way through; this suite is about the enrolment that follows it.
    bindColdStartVault(available: true)->shouldReceive('forget')->andReturnTrue();

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'settings-pass')
        ->call('setPin', '123456', '123456')
        ->call('startEnroll')
        ->assertNotDispatched('beatrax:webauthn-create')
        ->assertSet('confirmingEnroll', true)
        ->assertSet('biometricEnrolled', false)
        ->call('enrollWithPin', '123456')
        ->assertSet('biometricEnrolled', true)
        ->assertSet('confirmingEnroll', false)
        ->assertSet('flashMessage', '');
});

// The whole point of the gate: the button alone must not reach the vault. A
// vault that was never asked is the only assertion that separates this from a
// vault that was asked and said no.
it('does not reach the vault at all until a PIN is typed', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-no-pin'));
    bindColdStartVault(available: true, enrolls: null)->shouldReceive('forget')->andReturnTrue();

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'settings-pass')
        ->call('setPin', '123456', '123456')
        ->call('startEnroll')
        ->call('enrollWithPin', '')
        ->assertSet('biometricEnrolled', false)
        ->assertSee('Enter your PIN.');
});

it('does not reach the vault on a wrong PIN', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-wrong-enroll-pin'));
    bindColdStartVault(available: true, enrolls: null)->shouldReceive('forget')->andReturnTrue();

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'settings-pass')
        ->call('setPin', '123456', '123456')
        ->call('startEnroll')
        ->call('enrollWithPin', '000000')
        ->assertSet('biometricEnrolled', false)
        ->assertSet('confirmingEnroll', true)
        ->assertSee('Incorrect PIN.');
});

// The key the vault is handed comes out of the PIN, not out of the session, so
// a session holding no key is not the obstacle it used to be -- and no session
// that happens to hold one is a way past the PIN either.
it('arms the vault from the PIN even when the session is holding no key', function (): void {
    $user = coldStartSettingsUser('cold-withheld');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');
    bindColdStartVault(available: true);

    /** @var Session $session */
    $session = app(Session::class);
    app(AppLockKeyService::class)->withhold($session);

    Livewire::test(AppLockSettingsSection::class)
        ->call('startEnroll')
        ->call('enrollWithPin', '123456')
        ->assertSet('biometricEnrolled', true)
        ->assertSet('flashMessage', '');
});

it('reports a device that declines to store the key', function (): void {
    $this->actingAs(coldStartSettingsUser('cold-declines'));
    bindColdStartVault(available: true, enrolls: false)->shouldReceive('forget')->andReturnTrue();

    Livewire::test(AppLockSettingsSection::class)
        ->set('accountPassword', 'settings-pass')
        ->call('setPin', '123456', '123456')
        ->call('startEnroll')
        ->call('enrollWithPin', '123456')
        ->assertSet('biometricEnrolled', false)
        ->assertSee('Your device declined to store the key.');
});

// In the shell with no OS gate the browser event resolves to nothing, so this
// says what is missing rather than appearing to hang. Which is the app's half:
// this is the branch an Android phone that unlocks a dozen other apps takes,
// because the vault's Android side stores nothing yet.
it('refuses in the shell rather than dispatching into nothing, and does not blame the phone', function (): void {
    $user = coldStartSettingsUser('cold-shell');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');
    bindColdStartVault(available: false);
    app(ConfigRepository::class)->set('nativephp-internal.running', true);

    Livewire::test(AppLockSettingsSection::class)
        ->call('startEnroll')
        ->assertNotDispatched('beatrax:webauthn-create')
        ->assertSee('This version of Beatrax has nowhere to store an unlock key, so biometric unlock is not offered. Your device is not the limitation.')
        ->assertDontSee('not available on this device');
});

it('offers no biometric row without blaming the device it is drawn on', function (): void {
    $user = coldStartSettingsUser('cold-empty-state');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');
    bindColdStartVault(available: false);

    Livewire::test(AppLockSettingsSection::class)
        ->assertSee('This version of Beatrax cannot offer biometric unlock. Your PIN is the only unlock here.')
        ->assertDontSee('not available on this device');
});

it('still asks the browser when there is no shell and no OS gate', function (): void {
    $user = coldStartSettingsUser('cold-browser');
    $this->actingAs($user);
    app(AppLockProvisioner::class)->enable($user->id, '123456', 'settings-pass');
    bindColdStartVault(available: false);
    // The browser path writes the wrap blob into the app's own SQLite file, so
    // it is only offered where the bound shield really protects those bytes.
    bindColdStartProtectingShield();

    // The PIN comes first on this road too: the ceremony travels to the browser
    // and back, so what leaves with it is a proof the PIN was just typed.
    Livewire::test(AppLockSettingsSection::class)
        ->call('startEnroll')
        ->assertNotDispatched('beatrax:webauthn-create')
        ->assertSet('confirmingEnroll', true)
        ->call('enrollWithPin', '123456')
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
    $vault->shouldReceive('forget')->once()->with($user->id)->andReturnTrue();

    Livewire::test(AppLockSettingsSection::class)
        ->call('deenroll', '123456')
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
        ->call('deenroll', '000000')
        ->assertSet('biometricEnrolled', true);
});
