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
use Modules\Mobile\Internal\Http\Livewire\MobileLockScreen;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;
use Psr\Log\LoggerInterface;

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
    app()->bind(BiometricKeyVault::class, fn ($app) => new class($app->make(BiometricKeyBlobCodec::class), $app->make(LoggerInterface::class), $result) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly BiometricRecoverResult $result,
        ) {
            parent::__construct($codec, $log);
        }

        public function recover(int $userId, string $reason = 'Unlock Beatrax'): BiometricRecoverResult
        {
            return $this->result;
        }

        public function completePendingRecover(int $userId): BiometricRecoverResult
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

it('async recovered admits + redirects via the BiometricVault.Recovered event', function (): void {
    lockedColdStartUser('cs-async-ok');
    $key = str_repeat('k', 32);
    bindVaultRecover(BiometricRecoverResult::recovered($key));

    Livewire::test(MobileLockScreen::class)->dispatch('native:BiometricVault.Recovered')->assertRedirect(route('dashboard'));

    expect(released())->toBe($key);
});

it('async recovered is REFUSED when not enrolled (stale-blob guard on the async path)', function (): void {
    lockedColdStartUser('cs-async-not-enrolled', enrolled: false);
    bindVaultRecover(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->dispatch('native:BiometricVault.Recovered')->assertNoRedirect();

    expect(released())->toBeNull();
});

it('async recovered is REFUSED when the PIN floor is overdue', function (): void {
    lockedColdStartUser('cs-async-floor', floorDaysAgo: 20);
    bindVaultRecover(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->dispatch('native:BiometricVault.Recovered')->assertNoRedirect();

    expect(released())->toBeNull();
});

// A refused gate is not a reason to leave the released blob where it is. The
// enclave let it go before this screen ran, and the poll is its only consumer,
// so the poll comes first and the refusal decides what to do with the answer.
function bindVaultCountingPolls(BiometricRecoverResult $result): object
{
    $tally = new class
    {
        public int $polls = 0;
    };

    app()->bind(BiometricKeyVault::class, fn ($app): BiometricKeyVault => new class($app->make(BiometricKeyBlobCodec::class), $app->make(LoggerInterface::class), $result, $tally) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly BiometricRecoverResult $result,
            private readonly object $tally,
        ) {
            parent::__construct($codec, $log);
        }

        public function completePendingRecover(int $userId): BiometricRecoverResult
        {
            $this->tally->polls++;

            return $this->result;
        }
    });

    return $tally;
}

it('drains the native slot even though the stale-blob guard refuses', function (): void {
    lockedColdStartUser('cs-async-drain-not-enrolled', enrolled: false);
    $tally = bindVaultCountingPolls(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->dispatch('native:BiometricVault.Recovered')->assertNoRedirect();

    expect($tally->polls)->toBe(1)
        ->and(released())->toBeNull();
});

it('drains the native slot even though the PIN floor refuses', function (): void {
    lockedColdStartUser('cs-async-drain-floor', floorDaysAgo: 20);
    $tally = bindVaultCountingPolls(BiometricRecoverResult::recovered(str_repeat('k', 32)));

    Livewire::test(MobileLockScreen::class)->dispatch('native:BiometricVault.Recovered')->assertNoRedirect();

    expect($tally->polls)->toBe(1)
        ->and(released())->toBeNull();
});

it('onColdStartFailed is a no-op — never admits, never redirects', function (): void {
    lockedColdStartUser('cs-async-failed');

    Livewire::test(MobileLockScreen::class)->dispatch('native:BiometricVault.Failed')->assertNoRedirect();

    expect(released())->toBeNull();
});

// The screen fires the prompt from its own x-init, and the PIN pad stays live
// underneath it, so both roads are open at once and only one of them is taken.
// On Android the prompt is a window rather than part of the page: it survived
// the unlock and stood over an app that was already open.
function bindVaultCountingCancels(): object
{
    $tally = new class
    {
        public int $cancels = 0;
    };

    app()->bind(BiometricKeyVault::class, fn ($app) => new class($app->make(BiometricKeyBlobCodec::class), $app->make(LoggerInterface::class), $tally) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly object $tally,
        ) {
            parent::__construct($codec, $log);
        }

        public function cancelPrompt(): void
        {
            $this->tally->cancels++;
        }

        protected function runtimeAvailable(): bool
        {
            return true;
        }

        protected function vaultCapability(): array
        {
            return ['available' => true, 'reason' => 'available'];
        }
    });

    return $tally;
}

it('takes the standing prompt down when the PIN is what opened the lock', function (): void {
    lockedColdStartUser('pin-beats-prompt');
    $tally = bindVaultCountingCancels();

    Livewire::test(MobileLockScreen::class)
        ->call('submit', '123456')
        ->assertRedirect(route('dashboard'));

    expect($tally->cancels)->toBe(1);
});

// A wrong PIN is not an answer to the prompt. Taking it down here would retire
// the road the reader is most likely to take next, on the screen where they
// have just demonstrated they do not remember the other one.
it('leaves the prompt standing when the PIN was wrong', function (): void {
    lockedColdStartUser('wrong-pin-keeps-prompt');
    $tally = bindVaultCountingCancels();

    Livewire::test(MobileLockScreen::class)
        ->call('submit', '999999')
        ->assertNoRedirect();

    expect($tally->cancels)->toBe(0);
});

// A PIN that never reaches the gateway must not reach the prompt either.
it('leaves the prompt standing when the PIN was the wrong length', function (): void {
    lockedColdStartUser('short-pin-keeps-prompt');
    $tally = bindVaultCountingCancels();

    Livewire::test(MobileLockScreen::class)
        ->call('submit', '12')
        ->assertNoRedirect();

    expect($tally->cancels)->toBe(0);
});
