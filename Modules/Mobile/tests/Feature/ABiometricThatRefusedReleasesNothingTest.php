<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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

// The unlock boundary admits on `isRecovered() && dataKey !== null`, so what
// keeps a refusal from opening the ledger is that a refusal carries no key at
// all. That is a property of the result type rather than of the screen, and it
// is asserted here over every outcome the type can name — including the one
// that means no prompt ever ran.

/**
 * @return list<string> every factory that names an outcome other than a real
 *                      recovery, read off the type rather than listed here
 */
function refusedBiometricOutcomeFactories(): array
{
    $names = [];

    foreach ((new ReflectionClass(BiometricRecoverResult::class))->getMethods() as $method) {
        $returns = $method->getReturnType();

        if ($method->isPublic()
            && $method->isStatic()
            && $method->getNumberOfParameters() === 0
            && $returns instanceof ReflectionNamedType
            && $returns->getName() === BiometricRecoverResult::class) {
            $names[] = $method->getName();
        }
    }

    sort($names);

    return $names;
}

function refusedBiometricLockedUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    /** @var Session $session */
    $session = app(Session::class);

    /** @var MobileLockGateway $lock */
    $lock = app(MobileLockGateway::class);
    $lock->enableAppLock((int) $user->id, '123456', 'account-password', $session);
    $lock->markColdStartEnrolled((int) $user->id, true);

    DB::table('user_app_lock_configs')->where('user_id', $user->id)
        ->update(['last_pin_unlock_at' => CarbonImmutable::now()->toDateTimeString()]);

    test()->session([AppLockTestHarness::LOCKED_SESSION_KEY => true]);

    return $user;
}

function refusedBiometricBindVault(BiometricRecoverResult $result): void
{
    app()->bind(BiometricKeyVault::class, fn ($app): BiometricKeyVault => new class($app->make(BiometricKeyBlobCodec::class), $app->make(LoggerInterface::class), $result) extends BiometricKeyVault
    {
        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly BiometricRecoverResult $dictated,
        ) {
            parent::__construct($codec, $log);
        }

        public function recover(int $userId, string $reason = 'Unlock Beatrax'): BiometricRecoverResult
        {
            return $this->dictated;
        }

        public function completePendingRecover(): BiometricRecoverResult
        {
            return $this->dictated;
        }
    });
}

function refusedBiometricReleasedKey(): ?string
{
    /** @var Session $session */
    $session = app(Session::class);

    return app(AppLockKeyService::class)->release($session);
}

it('carries a key on the one outcome that means a live biometric answered, and on no other', function (): void {
    $factories = refusedBiometricOutcomeFactories();

    expect($factories)->not->toBe([], 'the outcome type names its refusals through zero-argument factories; finding none means this guard read nothing');
    expect(count($factories))->toBeGreaterThanOrEqual(5);

    $carried = [];

    foreach ($factories as $factory) {
        /** @var BiometricRecoverResult $result */
        $result = BiometricRecoverResult::{$factory}();

        if ($result->isRecovered() || $result->dataKey !== null) {
            $carried[] = $factory;
        }
    }

    expect($carried)->toBe(
        [],
        'An outcome that is not a completed recovery handed back key material: '.implode(', ', $carried).".\n".
        "The unlock boundary admits on isRecovered() plus a non-null dataKey, so a refusal that carries either\n".
        "one opens the ledger with no biometric behind it — and `unavailable` is the outcome for a runtime where\n".
        'no prompt was ever shown. A new outcome belongs beside the others, carrying nothing.',
    );

    $key = str_repeat('k', 32);
    $recovered = BiometricRecoverResult::recovered($key);

    expect($recovered->isRecovered())->toBeTrue()
        ->and($recovered->dataKey)->toBe($key);
});

it('releases no key on the lock screen for any outcome that is not a completed recovery', function (): void {
    $factories = refusedBiometricOutcomeFactories();
    expect($factories)->not->toBe([]);

    foreach ($factories as $index => $factory) {
        /** @var BiometricRecoverResult $result */
        $result = BiometricRecoverResult::{$factory}();

        refusedBiometricLockedUser('cold-start-refused-sync-'.$index);
        refusedBiometricBindVault($result);

        Livewire::test(MobileLockScreen::class)
            ->call('biometricPrompt')
            ->assertNoRedirect();

        expect(refusedBiometricReleasedKey())->toBeNull("the {$factory} outcome must leave the app locked");
    }
});

// The Android leg finishes on an event rather than on the prompt's return, and
// it is a second entry into the same admission. A refusal arriving there has to
// be refused for the same reason.
it('releases no key on the event the asynchronous leg finishes on either', function (): void {
    $factories = refusedBiometricOutcomeFactories();
    expect($factories)->not->toBe([]);

    foreach ($factories as $index => $factory) {
        /** @var BiometricRecoverResult $result */
        $result = BiometricRecoverResult::{$factory}();

        refusedBiometricLockedUser('cold-start-refused-async-'.$index);
        refusedBiometricBindVault($result);

        Livewire::test(MobileLockScreen::class)
            ->dispatch('cold-start-recovered')
            ->assertNoRedirect();

        expect(refusedBiometricReleasedKey())->toBeNull("the {$factory} outcome must leave the app locked on the asynchronous leg");
    }
});
