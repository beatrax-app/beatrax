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

/*
 * MobileImportBootstrapTest — Task 5 (Phase 15 import-join, 6g): the
 * fresh-device local-identity bootstrap the mobile welcome screen's
 * "Import from another device" CTA now leads into.
 *
 * Mirrors MobileFirstLaunchWelcomeGateTest's precedent of neutralizing the
 * shared-test-harness collision with Desktop's own EnsureDatabaseReady
 * middleware (both boot the same repo-root bootstrap/app.php in this
 * process — a test-infrastructure artifact, never a production concern,
 * where mobile-app is a fully separate application root).
 */

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
        ->set('pin', '4269')
        ->set('confirmPin', '4269')
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

    // B2: no epoch minted — enableSyncIdentityWithoutEpoch() never touches
    // sync_encryption_state / the GDK keyring.
    expect($db->connection()->table('sync_encryption_state')->where('user_id', $user->id)->exists())->toBeFalse();
});

it('continueToPairing() forgets the recovery-codes session key and redirects into mobile.pair?mode=import', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    $component = Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-continue')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'a-genuinely-long-password')
        ->set('pin', '4269')
        ->set('confirmPin', '4269')
        ->call('submit')
        ->assertSet('step', 'recovery_codes');

    expect(session('auth.signup.recovery_codes_plain'))->not->toBeNull();

    $component->call('continueToPairing')
        ->assertRedirect(route('mobile.pair', ['mode' => 'import']));

    expect(session('auth.signup.recovery_codes_plain'))->toBeNull('the recovery-codes ceremony must forget the session key exactly once, mirroring RecoveryCodesDisplay');
});

it('rejects mismatched passwords and a too-short PIN without provisioning anything', function (): void {
    $this->withoutMiddleware(EnsureDatabaseReady::class);

    Livewire::test(MobileImportBootstrap::class)
        ->set('username', 'phone-owner-bad')
        ->set('password', 'a-genuinely-long-password')
        ->set('passwordConfirmation', 'does-not-match')
        ->set('pin', '4269')
        ->set('confirmPin', '4269')
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
        ->assertSet('flashMessage', 'PIN must be at least 4 digits.');

    expect(User::query()->count())->toBe(0, 'a rejected PIN must never reach SignupAction');
});
