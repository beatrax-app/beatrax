<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Events\AppLockPassphraseChanged;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\ColdStartEnrollmentService;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

// The secure enclave cannot be reached from the repo toolchain, so it is faked
// here and only the orchestration around it is exercised.
function fakeEnclaveVault(): BiometricKeyVault
{
    return new class(app(BiometricKeyBlobCodec::class), app(CurrentUser::class), app(LoggerInterface::class)) extends BiometricKeyVault
    {
        public bool $enrolled = false;

        public bool $cleared = false;

        protected function runtimeAvailable(): bool
        {
            return true;
        }

        // The enclave path is iOS, which reports Darwin. Without pinning it the
        // fake inherits the HOST's PHP_OS_FAMILY, so platformCanStore() answers
        // false on a Linux runner and every availability assertion here passes
        // on a Mac and fails in CI.
        protected function platformFamily(): string
        {
            return 'Darwin';
        }

        public function enroll(string $dataKey): bool
        {
            $this->enrolled = true;

            return true;
        }

        public function clear(): void
        {
            $this->cleared = true;
        }
    };
}

function enrollmentUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
}

it('enrolls with the correct PIN: wraps the live key and sets the flag', function (): void {
    $user = enrollmentUser('enroll-ok');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    $vault = fakeEnclaveVault();
    $service = new ColdStartEnrollmentService($vault, app(MobileLockGateway::class));

    $ok = $service->enroll((int) $user->id, '123456', app(Session::class));

    expect($ok)->toBeTrue()
        ->and($vault->enrolled)->toBeTrue()
        ->and($service->isEnrolled((int) $user->id))->toBeTrue();
});

it('does not enroll on a wrong PIN — nothing is stored, flag stays false', function (): void {
    $user = enrollmentUser('enroll-wrong-pin');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    $vault = fakeEnclaveVault();
    $service = new ColdStartEnrollmentService($vault, app(MobileLockGateway::class));

    $ok = $service->enroll((int) $user->id, '000000', app(Session::class));

    expect($ok)->toBeFalse()
        ->and($vault->enrolled)->toBeFalse()
        ->and($service->isEnrolled((int) $user->id))->toBeFalse();
});

it('does not enroll when the enclave vault is unavailable', function (): void {
    $user = enrollmentUser('enroll-unavailable');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    // The real vault reports runtimeAvailable() false in the repo toolchain.
    $vault = app(BiometricKeyVault::class);
    $service = new ColdStartEnrollmentService($vault, app(MobileLockGateway::class));

    expect($service->enroll((int) $user->id, '123456', app(Session::class)))->toBeFalse()
        ->and($service->isEnrolled((int) $user->id))->toBeFalse();
});

it('disable clears the enclave and resets the flag', function (): void {
    $user = enrollmentUser('enroll-disable');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    $vault = fakeEnclaveVault();
    $service = new ColdStartEnrollmentService($vault, app(MobileLockGateway::class));

    $service->enroll((int) $user->id, '123456', app(Session::class));
    expect($service->isEnrolled((int) $user->id))->toBeTrue();

    $service->disable((int) $user->id);

    expect($vault->cleared)->toBeTrue()
        ->and($service->isEnrolled((int) $user->id))->toBeFalse();
});

it('pinFloorDue is true when the user has never completed a PIN unlock', function (): void {
    $user = enrollmentUser('floor-fresh');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    // enable() stamps last_activity_at but NOT last_pin_unlock_at.
    expect(app(MobileLockGateway::class)->pinFloorDue((int) $user->id))->toBeTrue();
});

it('pinFloorDue is false right after a successful PIN unlock (which refreshes the anchor)', function (): void {
    $user = enrollmentUser('floor-fresh-unlock');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    app(MobileLockGateway::class)->verifyPin((int) $user->id, '123456', app(Session::class));

    expect(app(MobileLockGateway::class)->pinFloorDue((int) $user->id))->toBeFalse();
});

it('pinFloorDue is true when the last PIN unlock is older than the floor window', function (): void {
    $user = enrollmentUser('floor-stale');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->verifyPin((int) $user->id, '123456', app(Session::class));

    DB::table('user_app_lock_configs')->where('user_id', $user->id)
        ->update(['last_pin_unlock_at' => CarbonImmutable::now()->subDays(20)->toDateTimeString()]);

    expect(app(MobileLockGateway::class)->pinFloorDue((int) $user->id))->toBeTrue();
});

it('lock screen offers biometric when enrolled, vault available, and floor not due', function (): void {
    $user = enrollmentUser('mount-ready');
    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->verifyPin((int) $user->id, '123456', app(Session::class));
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    app()->bind(BiometricKeyVault::class, fn () => fakeEnclaveVault());

    Livewire::test(MobileLockScreen::class)->assertSet('biometricAvailable', true);
});

it('lock screen hides biometric when the PIN floor is overdue (forces PIN)', function (): void {
    $user = enrollmentUser('mount-floor-due');
    test()->actingAs($user);
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);
    // Nothing records a PIN unlock, so the floor is due.

    app()->bind(BiometricKeyVault::class, fn () => fakeEnclaveVault());

    Livewire::test(MobileLockScreen::class)->assertSet('biometricAvailable', false);
});

it('clears the cold-start enrollment when the app-lock data key actually rotates', function (): void {
    $user = enrollmentUser('rotate-clear');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    $vault = fakeEnclaveVault();
    app()->instance(BiometricKeyVault::class, $vault);
    app()->forgetInstance(ColdStartEnrollmentService::class);

    // Different old and new KEKs, so the data key genuinely rotated.
    event(new AppLockPassphraseChanged((int) $user->id, str_repeat('a', 32), str_repeat('b', 32)));

    expect($vault->cleared)->toBeTrue()
        ->and(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse();
});

it('leaves the enrollment intact on a normal PIN change (data key unchanged)', function (): void {
    $user = enrollmentUser('rotate-noop');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    $vault = fakeEnclaveVault();
    app()->instance(BiometricKeyVault::class, $vault);
    app()->forgetInstance(ColdStartEnrollmentService::class);

    // The same KEK on both sides: a PIN change that left the data key alone.
    event(new AppLockPassphraseChanged((int) $user->id, str_repeat('a', 32), str_repeat('a', 32)));

    expect($vault->cleared)->toBeFalse()
        ->and(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeTrue();
});

it('re-enabling the app lock (which re-provisions the data key) resets the cold-start flag', function (): void {
    $user = enrollmentUser('reprovision-resets');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);
    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeTrue();

    // Re-provisioning mints a new data key, leaving any cold-start blob wrapping
    // the old one.
    app(AppLockProvisioner::class)->enable((int) $user->id, '654321', 'account-password');

    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse();
});

it('disabling the app lock resets the cold-start flag', function (): void {
    $user = enrollmentUser('disable-resets');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');
    app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);

    app(AppLockProvisioner::class)->disable((int) $user->id, '123456');

    expect(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse();
});

it('a wrong-PIN enroll consumes a PIN attempt (shares the lock-screen backoff)', function (): void {
    $user = enrollmentUser('enroll-consumes-attempt');
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    $before = app(MobileLockGateway::class)->remainingPinAttempts((int) $user->id);

    $service = new ColdStartEnrollmentService(fakeEnclaveVault(), app(MobileLockGateway::class));
    $service->enroll((int) $user->id, '000000', app(Session::class));

    $after = app(MobileLockGateway::class)->remainingPinAttempts((int) $user->id);
    expect($after)->toBeLessThan($before);
});
