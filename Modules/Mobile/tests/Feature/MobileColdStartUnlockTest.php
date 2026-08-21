<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Auth\Public\Testing\AppLockTestHarness;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;

uses(RefreshDatabase::class);

/**
 * @param  bool  $enrolled  set the cold-start enrollment flag
 * @param  int  $floorDaysAgo  age of the last PIN unlock (0 = fresh, >=14 = overdue)
 */
function lockedColdStartUser(string $username, bool $enrolled = true, int $floorDaysAgo = 0): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    // enable() is what creates the user_app_lock_configs row, and it also resets
    // the cold-start flag.
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    if ($enrolled) {
        app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);
    }
    DB::table('user_app_lock_configs')->where('user_id', $user->id)
        ->update(['last_pin_unlock_at' => CarbonImmutable::now()->subDays($floorDaysAgo)->toDateTimeString()]);

    // SESSION_KEY true is the locked cold-start state, where release() gives null.
    test()->session([AppLockTestHarness::LOCKED_SESSION_KEY => true]);

    return $user;
}

// The enclave is unreachable in the repo toolchain, so its outcome is dictated.
function bindVaultRecover(BiometricRecoverResult $result): void
{
    app()->bind(BiometricKeyVault::class, fn ($app) => new class($app->make(BiometricKeyBlobCodec::class), $app->make(CurrentUser::class), $result) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            CurrentUser $currentUser,
            private readonly BiometricRecoverResult $result,
        ) {
            parent::__construct($codec, $currentUser);
        }

        public function recover(string $reason = 'Unlock beatrax'): BiometricRecoverResult
        {
            return $this->result;
        }

        public function completePendingRecover(): BiometricRecoverResult
        {
            return $this->result;
        }
    });
}

function released(): ?string
{
    /** @var Session $session */
    $session = app(Session::class);

    return app(AppLockKeyService::class)->release($session);
}

// The unlock boundary admits only when the enrollment flag, the PIN floor and a
// genuine vault recovery all agree. The flag is what refuses a stale enclave blob
// left behind by an app-lock re-provision, which the enclave itself would happily
// hand back.

it('enrolled + floor-fresh + RECOVERED admits the key and redirects', function (): void {
    lockedColdStartUser('cs-recovered');
    $key = str_repeat('k', 32);
    bindVaultRecover(BiometricRecoverResult::recovered($key));

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertRedirect(route('dashboard'));

    expect(released())->toBe($key);
});

it('enrolled + floor-fresh + CANCELED does not admit', function (): void {
    lockedColdStartUser('cs-canceled');
    bindVaultRecover(BiometricRecoverResult::canceled());

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('enrolled + floor-fresh + FAILED does not admit', function (): void {
    lockedColdStartUser('cs-failed');
    bindVaultRecover(BiometricRecoverResult::failed());

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('enrolled + floor-fresh + MISSING does not admit', function (): void {
    lockedColdStartUser('cs-missing');
    bindVaultRecover(BiometricRecoverResult::missing());

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('PENDING_ASYNC does not admit synchronously', function (): void {
    lockedColdStartUser('cs-pending');
    bindVaultRecover(BiometricRecoverResult::pendingAsync());

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('does NOT admit when NOT enrolled, even if the vault would recover a key (stale-blob guard)', function (): void {
    lockedColdStartUser('cs-not-enrolled', enrolled: false);
    bindVaultRecover(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('does NOT admit when the PIN floor is overdue, even if the vault would recover (floor guard)', function (): void {
    lockedColdStartUser('cs-floor-overdue', floorDaysAgo: 20);
    bindVaultRecover(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertNoRedirect();

    expect(released())->toBeNull();
});

// Android finishes the recovery asynchronously: the key arrives on a
// BiometricVault.Recovered event rather than from the prompt call's return.

it('async recovered admits + redirects via the cold-start-recovered event', function (): void {
    lockedColdStartUser('cs-async-ok');
    $key = str_repeat('k', 32);
    bindVaultRecover(BiometricRecoverResult::recovered($key));

    Livewire::test(MobileLockScreen::class)->dispatch('cold-start-recovered')->assertRedirect(route('dashboard'));

    expect(released())->toBe($key);
});

it('async recovered is REFUSED when not enrolled (stale-blob guard on the async path)', function (): void {
    lockedColdStartUser('cs-async-not-enrolled', enrolled: false);
    bindVaultRecover(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->dispatch('cold-start-recovered')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('async recovered is REFUSED when the PIN floor is overdue', function (): void {
    lockedColdStartUser('cs-async-floor', floorDaysAgo: 20);
    bindVaultRecover(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->dispatch('cold-start-recovered')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('onColdStartFailed is a no-op — never admits, never redirects', function (): void {
    lockedColdStartUser('cs-async-failed');

    Livewire::test(MobileLockScreen::class)->dispatch('cold-start-failed')->assertNoRedirect();

    expect(released())->toBeNull();
});
