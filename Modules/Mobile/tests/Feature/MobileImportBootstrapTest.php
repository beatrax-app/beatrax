<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
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
        ->assertSet('flashMessage', 'Passwords do not match.');

    expect(User::query()->count())->toBe(0);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-bad-pin')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '12')
        ->set('confirmPin', '12')
        ->call('submit')
        ->assertSet('step', 'collect_pin')
        ->assertSet('flashMessage', 'PIN must be at least 6 digits.');

    expect(User::query()->count())->toBe(0, 'a rejected PIN must never reach SignupAction');
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
