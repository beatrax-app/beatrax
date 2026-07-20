<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;

uses(RefreshDatabase::class);

/*
 * Component-seam trust boundary for cold-start biometric unlock. The unlock
 * boundary (biometricPrompt cold-start branch + onColdStartRecovered) enforces
 * TWO gates before admitting: the enrollment flag (so a stale blob left after an
 * app-lock re-provision is refused) AND the PIN floor (periodic mandatory PIN).
 * Beyond that it admits ONLY when the vault genuinely recovers a key. The native
 * enclave is faked; the app-side branching is fully exercised here.
 */

/**
 * A locked (cold-start) user with an app-lock config row.
 *
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

    // Creates the user_app_lock_configs row (and resets cold_start flag).
    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    if ($enrolled) {
        app(MobileLockGateway::class)->markColdStartEnrolled((int) $user->id, true);
    }
    DB::table('user_app_lock_configs')->where('user_id', $user->id)
        ->update(['last_pin_unlock_at' => CarbonImmutable::now()->subDays($floorDaysAgo)->toDateTimeString()]);

    // Force the locked (cold-start) state: SESSION_KEY=true → release() null.
    test()->session([LockStateManager::SESSION_KEY => true]);

    return $user;
}

/** Force the cold-start vault to return a specific recover()/complete outcome. */
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

// --- Happy path + recover-result mapping (enrolled, floor fresh) -------------

it('enrolled + floor-fresh + RECOVERED admits the key and redirects', function (): void {
    lockedColdStartUser('cs-recovered');
    $key = str_repeat('k', 32);
    bindVaultRecover(BiometricRecoverResult::recovered($key));

    Livewire::test(MobileLockScreen::class)->call('biometricPrompt')->assertRedirect(route('dashboard'));

    expect(released())->toBe($key);
});

it('enrolled + floor-fresh + CANCELED does not admit (T-15-14)', function (): void {
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

// --- Unlock-boundary GATES (the security controls) --------------------------

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

// --- Android async completion (item 1): the BiometricVault.Recovered event ---

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
