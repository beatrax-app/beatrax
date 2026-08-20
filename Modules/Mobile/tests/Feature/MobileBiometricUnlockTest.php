<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\BiometricDeviceStore;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Identity\BiometricUnlockBridge;

uses(RefreshDatabase::class);

function mobileBiometricTestUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('whatever-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

// The native Biometrics facade installs only under mobile-app/, so from the
// repo-root toolchain isAvailable() can only ever be false. The success and abort
// scenarios swap in a bridge subclass instead; the real sensor is covered by a
// manual on-device pass.

it('BiometricUnlockBridge isAvailable() returns false without the native facade — never fatal in tests/web', function (): void {
    $bridge = new BiometricUnlockBridge;

    expect($bridge->isAvailable())->toBeFalse();
});

it('BiometricUnlockBridge prompt() returns false when unavailable — bool-only, never touches the native facade', function (): void {
    $bridge = new BiometricUnlockBridge;

    expect($bridge->prompt())->toBeFalse();
    expect($bridge->prompt('Custom reason'))->toBeFalse();
});

it('MobileLockScreen class exists and is Livewire-registered', function (): void {
    expect(class_exists(MobileLockScreen::class))->toBeTrue();
});

it('biometric success releases the LOCK-04 key and redirects to the intended URL (T-15-14)', function (): void {
    $user = mobileBiometricTestUser('mobile-bio-success');
    test()->actingAs($user);

    // The biometric trigger derives no key of its own: it confirms, then reads
    // through the same AppLockKeyService the rest of the app uses. So the session
    // has to be holding one already.
    /** @var Session $session */
    $session = app(Session::class);
    (new LockStateManager)->unlock($session, str_repeat('k', 32));

    // Enroll an armed biometric credential so biometricAvailable is true.
    /** @var BiometricDeviceStore $store */
    $store = app(BiometricDeviceStore::class);
    $store->store((int) $user->id, 'mobile-cred-1', 'iPhone', str_repeat('s', 40), null, 'nativephp_mobile');

    app()->bind(BiometricUnlockBridge::class, fn () => new class extends BiometricUnlockBridge
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function prompt(string $reason = 'Unlock beatrax'): bool
        {
            return true;
        }
    });

    Livewire::test(MobileLockScreen::class)
        ->call('biometricPrompt')
        ->assertRedirect(route('dashboard'));

    /** @var AppLockKeyService $keyService */
    $keyService = app(AppLockKeyService::class);
    expect($keyService->release($session))->not->toBeNull();
});

it('biometric abort never releases the key — data stays encrypted, PIN pad remains the fallback (T-15-15)', function (): void {
    $user = mobileBiometricTestUser('mobile-bio-abort');
    test()->actingAs($user);
    test()->session([LockStateManager::SESSION_KEY => true]);

    app()->bind(BiometricUnlockBridge::class, fn () => new class extends BiometricUnlockBridge
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function prompt(string $reason = 'Unlock beatrax'): bool
        {
            return false;
        }
    });

    Livewire::test(MobileLockScreen::class)
        ->call('biometricPrompt')
        ->assertNoRedirect();

    /** @var Session $session */
    $session = app(Session::class);
    /** @var AppLockKeyService $keyService */
    $keyService = app(AppLockKeyService::class);
    expect($keyService->release($session))->toBeNull();
});

it('biometric success against a genuinely locked session (no data key) falls through silently — the PIN pad completes the unlock', function (): void {
    $user = mobileBiometricTestUser('mobile-bio-nokey');
    test()->actingAs($user);
    test()->session([LockStateManager::SESSION_KEY => true]);

    app()->bind(BiometricUnlockBridge::class, fn () => new class extends BiometricUnlockBridge
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function prompt(string $reason = 'Unlock beatrax'): bool
        {
            return true;
        }
    });

    Livewire::test(MobileLockScreen::class)
        ->call('biometricPrompt')
        ->assertNoRedirect();

    /** @var Session $session */
    $session = app(Session::class);
    /** @var AppLockKeyService $keyService */
    $keyService = app(AppLockKeyService::class);
    expect($keyService->release($session))->toBeNull();
});

it('PIN path still works on the mobile lock screen — the Auth chain is reused unchanged', function (): void {
    $user = mobileBiometricTestUser('mobile-pin-still-works');
    test()->actingAs($user);
    test()->session([LockStateManager::SESSION_KEY => true]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable((int) $user->id, '123456', 'whatever-password');

    Livewire::test(MobileLockScreen::class)
        ->call('submit', '123456')
        ->assertRedirect(route('dashboard'));

    /** @var Session $session */
    $session = app(Session::class);
    /** @var LockStateManager $lockState */
    $lockState = app(LockStateManager::class);
    expect($lockState->isLocked($session))->toBeFalse();
});

it('wrong PIN on the mobile lock screen sets flash message and leaves the session locked', function (): void {
    $user = mobileBiometricTestUser('mobile-pin-wrong');
    test()->actingAs($user);
    test()->session([LockStateManager::SESSION_KEY => true]);

    /** @var AppLockProvisioner $provisioner */
    $provisioner = app(AppLockProvisioner::class);
    $provisioner->enable((int) $user->id, '123456', 'whatever-password');

    Livewire::test(MobileLockScreen::class)
        ->call('submit', '000000')
        ->assertNoRedirect()
        ->assertSee('Incorrect PIN');
});

it('GET /mobile/lock renders 200 with the PIN pad and Sign out', function (): void {
    $user = mobileBiometricTestUser('mobile-lock-get');
    test()->actingAs($user);

    test()->withSession([LockStateManager::SESSION_KEY => true])
        ->get('/mobile/lock')
        ->assertOk()
        ->assertSee('Sign out');
});
