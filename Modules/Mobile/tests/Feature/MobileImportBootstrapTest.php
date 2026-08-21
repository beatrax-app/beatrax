<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Contracts\PasswordPolicy;
use Modules\Core\Models\User;
use Modules\Desktop\Internal\Http\Middleware\EnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Livewire\MobileImportBootstrap;

uses(RefreshDatabase::class);

// Desktop's EnsureDatabaseReady boots in this process too, because both modules
// share the repo-root bootstrap/app.php here. The collision is a test-harness
// artifact — in production mobile-app is a fully separate application root — so
// the middleware is dropped rather than satisfied.

it('GET /mobile/import renders 200 for a genuinely fresh (0-user) device', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    expect(User::query()->count())->toBe(0);

    $this->get(route('mobile.import'))
        ->assertOk()
        ->assertSee('Import from another device');
});

it('provisions a local user + app-lock + sync identity (no epoch) and advances to the recovery-codes ceremony', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    expect(User::query()->count())->toBe(0);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->call('submit')
        ->assertSet('step', 'recovery_codes')
        ->assertSet('flashMessage', '');

    expect(User::query()->count())->toBe(1, 'SignupAction must have created exactly one user');

    $user = User::query()->firstOrFail();

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    expect($provisioner->isEnabled((int) $user->id))->toBeTrue('the app-lock KEK must be provisioned (LOCK-04 precondition)');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $selfRow = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 1)
        ->first();
    expect($selfRow)->not->toBeNull('a self device_registry row must exist after identity bootstrap');
    expect($selfRow->confirmed_at)->not->toBeNull();

    // The bootstrap enables the sync identity without minting an epoch.
    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('continueToPairing() forgets the recovery-codes session key and redirects into mobile.pair?mode=import', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $component = Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-continue')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->call('submit')
        ->assertSet('step', 'recovery_codes');

    expect(session('auth.signup.recovery_codes_plain'))->not->toBeNull();

    $component->call('continueToPairing')
        ->assertRedirect(route('mobile.pair', ['mode' => 'import']));

    expect(session('auth.signup.recovery_codes_plain'))->toBeNull('the recovery-codes ceremony must forget the session key exactly once, mirroring RecoveryCodesDisplay');
});

// Cancelling out of the pairing ceremony walks back onto this route, which
// mounts the component afresh. That used to re-enter the recovery-codes step
// and print all ten codes again — under the line promising they would never be
// shown again, with the "I have saved these" box reset to unchecked.

it('never shows the recovery codes a second time when the pairing ceremony is cancelled', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-cancels')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->call('submit')
        ->assertSet('step', 'recovery_codes')
        ->html();

    /** @var list<string> $codes */
    $codes = session('auth.signup.recovery_codes_plain');
    expect($codes)->toHaveCount(10);

    $html = (string) Livewire::test(MobileImportBootstrap::class)
        ->assertSet('step', 'collect_pin')
        ->assertSet('alreadyProvisioned', true)
        ->html();

    expect($html)->toContain('import-already-provisioned');

    foreach ($codes as $code) {
        expect($html)->not->toContain($code, 'a screen that promised its codes are shown once must not print them again on the way back');
    }

    expect(session('auth.signup.recovery_codes_plain'))->toBeNull('the ceremony is over, so the plaintext copy goes with it');
});

// retryProvisioning() has to reach the originally submitted credentials: the
// public properties are emptied by then, and provisioning from those would mint a
// KEK from an empty passphrase.

it('retryProvisioning() without a pending-credentials session copy never provisions and returns to collect_pin', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    // A provisioning_failed device whose session-stashed credentials are gone: the
    // account is already committed and nothing is provisioned yet.
    $user = User::query()->create([
        'username' => 'phone-owner-retry-nopending',
        'password' => bcrypt('a-genuinely-long-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    Livewire::test(MobileImportBootstrap::class)
        ->set('step', 'provisioning_failed')
        ->call('retryProvisioning')
        ->assertSet('step', 'collect_pin');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    expect($provisioner->isEnabled((int) $user->id))->toBeFalse('a retry with no recoverable credentials must never provision anything — never a silent empty-passphrase KEK');
});

it('retryProvisioning() succeeds with the ORIGINALLY submitted credentials after a simulated provisioning failure', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $user = User::query()->create([
        'username' => 'phone-owner-retry-pending',
        'password' => bcrypt('a-genuinely-long-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    // What submit() stashes server-side before attempting provisionDeviceLocally().
    session()->put('mobile.import.pending_credentials', [
        'pin' => '426900',
        'password' => 'a-genuinely-long-password',
    ]);

    Livewire::test(MobileImportBootstrap::class)
        ->set('step', 'provisioning_failed')
        ->call('retryProvisioning')
        ->assertSet('step', 'recovery_codes')
        ->assertSet('flashMessage', '');

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    expect($provisioner->isEnabled((int) $user->id))->toBeTrue('retry must provision the app-lock KEK from the ORIGINAL non-empty credentials, not the emptied public properties');

    expect(session('mobile.import.pending_credentials'))->toBeNull('the pending-credentials session stash must be forgotten once provisioning succeeds');

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $selfRow = $db->connection()->table('device_registry')
        ->where('user_id', $user->id)
        ->where('is_self', 1)
        ->first();
    expect($selfRow)->not->toBeNull('sync identity must also be provisioned by the retry');
});

it('rejects mismatched passwords and a too-short PIN without provisioning anything', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-bad')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'does-not-match')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->call('submit')
        ->assertSet('step', 'collect_pin')
        ->assertHasErrors(['passwordConfirmation' => 'Passwords do not match.']);

    expect(User::query()->count())->toBe(0);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-bad-pin')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '12')
        ->set('confirmPin', '12')
        ->call('submit')
        ->assertSet('step', 'collect_pin')
        ->assertHasErrors(['pin' => 'PIN must be at least 6 digits.']);

    expect(User::query()->count())->toBe(0, 'a rejected PIN must never reach SignupAction');
});

// One message for the whole form reported the password rule and nothing else:
// an empty username and a two-digit PIN passed unremarked, and the single line
// it did print sat under "Confirm PIN", directly below that field's own
// "6-10 digits" hint, where the two read as a contradiction.

it('reports every broken rule on the field it belongs to, not one message for the form', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    Livewire::test(MobileImportBootstrap::class)
        ->call('submit')
        ->assertSet('step', 'collect_pin')
        ->assertSet('flashMessage', '')
        ->assertHasErrors([
            'username' => 'Username is required.',
            'password' => 'Use at least 12 characters.',
            'pin' => 'PIN must be at least 6 digits.',
        ]);

    expect(User::query()->count())->toBe(0);
});

it('reports a two-digit PIN under the PIN box while the password rule stays under the password box', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $html = (string) Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-shortvals')
        ->set('password', 'short')
        ->set('passwordConfirmation', 'short')
        ->set('pin', '12')
        ->set('confirmPin', '12')
        ->call('submit')
        ->assertHasErrors(['password', 'pin'])
        ->assertHasNoErrors(['username', 'passwordConfirmation', 'confirmPin'])
        ->html();

    // The component renders the error next to the control and marks it, so the
    // two ids below are the proof the message reached the right box.
    expect($html)->toContain('id="password-error"')
        ->and($html)->toContain('id="pin-error"')
        ->and(substr_count($html, 'aria-invalid="true"'))->toBe(2);
});

// A rejected submit used to empty both password boxes and keep the PIN, so the
// field that was wrong stayed put while a 12-character passphrase had to be
// retyped on a phone keyboard.

it('leaves every box as the reader typed it after a rejected submit', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-keeps-typing')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '12')
        ->set('confirmPin', '12')
        ->call('submit')
        ->assertHasErrors(['pin'])
        ->assertSet('password', 'a-genuinely-long-password')
        ->assertSet('passwordConfirmation', 'a-genuinely-long-password')
        ->assertSet('username', 'phone-owner-keeps-typing');
});

it('ticks the password requirements live, off the same binding the server validates', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $html = (string) Livewire::test(MobileImportBootstrap::class)->html();

    expect($html)->toContain('id="password-requirements"')
        ->and($html)->toContain('aria-describedby="password-requirements"')
        ->and($html)->toContain('At least 12 characters')
        ->and($html)->toContain(sprintf("passwordStrength(%d, 'password', 'passwordConfirmation')", PasswordPolicy::MINIMUM_LENGTH));
});

// Android paints the navigation bar over the page rather than beside it, so
// the button that ends each step was drawn under it and could be tapped only
// in its upper half. env(safe-area-inset-*) reads zero on Android; the --safe-*
// seam in app.css is the value that is right on both platforms.

it('keeps the button that ends each step clear of the system navigation bar', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $html = (string) Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-insets')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->call('submit')
        ->assertSet('step', 'recovery_codes')
        ->html();

    expect($html)->toContain('pb-[calc(2.5rem+var(--safe-bottom))]')
        ->and($html)->toContain('pl-[var(--safe-left)]')
        ->and($html)->toContain('pr-[var(--safe-right)]')
        ->and($html)->not->toContain('env(safe-area-inset-')
        ->and($html)->not->toContain('px-[var(--safe-');
});

it('places a username SignupAction refuses under the username box rather than on the form line', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'not a valid username')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->call('submit')
        ->assertSet('step', 'collect_pin')
        ->assertSet('flashMessage', '')
        ->assertHasErrors(['username']);

    expect(User::query()->count())->toBe(0);
});

// The first step creates nothing, so leaving it is free — and a guest screen
// carries no top bar, while the WebView's back gesture stays off.
it('offers a way back to the welcome screen before anything is provisioned', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $html = (string) Livewire::test(MobileImportBootstrap::class)->html();

    expect($html)->toContain(route('mobile.welcome'));
});

it('drops the way back once the device holds an identity', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $html = (string) Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '426900')
        ->set('confirmPin', '426900')
        ->call('submit')
        ->assertSet('step', 'recovery_codes')
        ->html();

    expect($html)->not->toContain(route('mobile.welcome'));
});

// $step carries no #[Locked], so the client decides what arrives in it. Typing
// it as a backed enum would make a crafted value a 500 rather than a harmless
// fallback, which is why the property stays a string and is read back with
// tryFrom(). A wrong fallback here would also print the recovery codes.
it('renders the first step when a step outside the wizard arrives from the wire', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $html = (string) Livewire::test(MobileImportBootstrap::class)
        ->set('step', 'not-a-step')
        ->assertSet('step', 'not-a-step')
        ->html();

    expect($html)->toContain(route('mobile.welcome'));
});
