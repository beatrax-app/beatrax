<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Core\Models\User;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;
use Modules\Mobile\Internal\Identity\MobileColdStartVault;

uses(RefreshDatabase::class);

/**
 * @param  ?string  $recovers  The key the enclave yields, or null to refuse.
 */
function coldStartEnclave(bool $enrolls = true, ?string $recovers = null): BiometricKeyVault
{
    return new class(app(BiometricKeyBlobCodec::class), app(CurrentUser::class), $enrolls, $recovers) extends BiometricKeyVault
    {
        public bool $cleared = false;

        public int $recoverCalls = 0;

        public function __construct(
            BiometricKeyBlobCodec $codec,
            CurrentUser $currentUser,
            private readonly bool $enrolls,
            private readonly ?string $recovers,
        ) {
            parent::__construct($codec, $currentUser);
        }

        protected function runtimeAvailable(): bool
        {
            return true;
        }

        public function enroll(string $dataKey): bool
        {
            return $this->enrolls;
        }

        public function recover(string $reason = 'Unlock Beatrax'): BiometricRecoverResult
        {
            $this->recoverCalls++;

            return $this->recovers === null
                ? BiometricRecoverResult::canceled()
                : BiometricRecoverResult::recovered($this->recovers);
        }

        public function clear(): void
        {
            $this->cleared = true;
        }
    };
}

function coldStartVaultUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('account-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'account-password');

    return $user;
}

// isEnrolled() reads the stored flag rather than the enclave: touching the entry
// would fire the biometric prompt just to render a button. And recover() does not
// prompt around the enclave, because the enclave entry is itself the gate and a
// second prompt would ask the user twice.

it('reports availability from the enclave', function (): void {
    $available = new MobileColdStartVault(coldStartEnclave(), app(MobileLockGateway::class));
    $unavailable = new MobileColdStartVault(app(BiometricKeyVault::class), app(MobileLockGateway::class));

    expect($available->isAvailable())->toBeTrue()
        ->and($unavailable->isAvailable())->toBeFalse();
});

it('records the enrollment against the user and reads it back', function (): void {
    $user = coldStartVaultUser('cold-start-enrolls');
    $vault = new MobileColdStartVault(coldStartEnclave(), app(MobileLockGateway::class));

    expect($vault->isEnrolled((int) $user->id))->toBeFalse()
        ->and($vault->enroll((int) $user->id, random_bytes(32)))->toBeTrue()
        ->and($vault->isEnrolled((int) $user->id))->toBeTrue();
});

// An enclave that refuses must leave the flag false, or the lock screen offers
// an unlock that cannot work and the user is stuck behind a dead button.
it('leaves the flag false when the enclave refuses to enroll', function (): void {
    $user = coldStartVaultUser('cold-start-refuses');
    $vault = new MobileColdStartVault(coldStartEnclave(enrolls: false), app(MobileLockGateway::class));

    expect($vault->enroll((int) $user->id, random_bytes(32)))->toBeFalse()
        ->and($vault->isEnrolled((int) $user->id))->toBeFalse();
});

// The flag is per user: one account enrolling must not light the button up for
// another account on the same device.
it('scopes the enrollment flag per user', function (): void {
    $first = coldStartVaultUser('cold-start-first');
    $second = coldStartVaultUser('cold-start-second');
    $vault = new MobileColdStartVault(coldStartEnclave(), app(MobileLockGateway::class));

    $vault->enroll((int) $first->id, random_bytes(32));

    expect($vault->isEnrolled((int) $first->id))->toBeTrue()
        ->and($vault->isEnrolled((int) $second->id))->toBeFalse();
});

it('returns the key the enclave yields, prompting exactly once', function (): void {
    $user = coldStartVaultUser('cold-start-recovers');
    $dataKey = random_bytes(32);
    $enclave = coldStartEnclave(recovers: $dataKey);
    $vault = new MobileColdStartVault($enclave, app(MobileLockGateway::class));

    expect($vault->recover((int) $user->id, 'Unlock Beatrax'))->toBe($dataKey)
        ->and($enclave->recoverCalls)->toBe(1);
});

it('returns nothing when the enclave recovery is not completed', function (): void {
    $user = coldStartVaultUser('cold-start-canceled');
    $vault = new MobileColdStartVault(coldStartEnclave(), app(MobileLockGateway::class));

    expect($vault->recover((int) $user->id, 'Unlock Beatrax'))->toBeNull();
});

it('clears both the enclave entry and the flag when forgetting', function (): void {
    $user = coldStartVaultUser('cold-start-forgets');
    $enclave = coldStartEnclave();
    $vault = new MobileColdStartVault($enclave, app(MobileLockGateway::class));

    $vault->enroll((int) $user->id, random_bytes(32));
    $vault->forget((int) $user->id);

    expect($enclave->cleared)->toBeTrue()
        ->and($vault->isEnrolled((int) $user->id))->toBeFalse();
});
