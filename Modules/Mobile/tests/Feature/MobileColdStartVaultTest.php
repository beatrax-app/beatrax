<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Public\Services\BiometricKeyBlobCodec;
use Modules\Auth\Public\Services\ColdStartEnrolmentFlag;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Identity\BiometricKeyVault;
use Modules\Mobile\Internal\Identity\BiometricRecoverResult;
use Modules\Mobile\Internal\Identity\MobileColdStartVault;
use Psr\Log\LoggerInterface;

uses(RefreshDatabase::class);

/**
 * @param  ?string  $recovers  The key the enclave yields, or null to refuse.
 * @param  ?BiometricRecoverResult  $refusal  Which refusal it answers with, where
 *                                            the case is about telling them apart.
 */
function coldStartEnclave(bool $enrolls = true, ?string $recovers = null, ?BiometricRecoverResult $refusal = null): BiometricKeyVault
{
    return new class(app(BiometricKeyBlobCodec::class), app(LoggerInterface::class), $enrolls, $recovers, $refusal) extends BiometricKeyVault
    {
        /** @var list<int> */
        public array $clearedFor = [];

        /** @var list<int> */
        public array $recoveredFor = [];

        /** @var list<int> */
        public array $enrolledFor = [];

        public function __construct(
            BiometricKeyBlobCodec $codec,
            LoggerInterface $log,
            private readonly bool $enrolls,
            private readonly ?string $recovers,
            private readonly ?BiometricRecoverResult $refusal,
        ) {
            parent::__construct($codec, $log);
        }

        protected function runtimeAvailable(): bool
        {
            return true;
        }

        // Without pinning it, this half of isAvailable() falls to the real
        // bridge, which no repo toolchain can reach, so every availability
        // assertion here would fail for the absence of a phone.
        /**
         * @return array{available?: bool, reason?: string}
         */
        protected function vaultCapability(): array
        {
            return ['available' => true, 'reason' => 'available'];
        }

        public function enroll(int $userId, string $dataKey): bool
        {
            $this->enrolledFor[] = $userId;

            return $this->enrolls;
        }

        public function recover(int $userId, string $reason = 'Unlock Beatrax'): BiometricRecoverResult
        {
            $this->recoveredFor[] = $userId;

            if ($this->recovers !== null) {
                return BiometricRecoverResult::recovered($this->recovers);
            }

            return $this->refusal ?? BiometricRecoverResult::canceled();
        }

        public bool $refusesClear = false;

        public function clear(int $userId): bool
        {
            $this->clearedFor[] = $userId;

            return ! $this->refusesClear;
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
    $available = new MobileColdStartVault(coldStartEnclave(), app(ColdStartEnrolmentFlag::class));
    $unavailable = new MobileColdStartVault(app(BiometricKeyVault::class), app(ColdStartEnrolmentFlag::class));

    expect($available->isAvailable())->toBeTrue()
        ->and($unavailable->isAvailable())->toBeFalse();
});

it('records the enrollment against the user and reads it back', function (): void {
    $user = coldStartVaultUser('cold-start-enrolls');
    $vault = new MobileColdStartVault(coldStartEnclave(), app(ColdStartEnrolmentFlag::class));

    expect($vault->isEnrolled((int) $user->id))->toBeFalse()
        ->and($vault->enroll((int) $user->id, random_bytes(32)))->toBeTrue()
        ->and($vault->isEnrolled((int) $user->id))->toBeTrue();
});

// An enclave that refuses must leave the flag false, or the lock screen offers
// an unlock that cannot work and the user is stuck behind a dead button.
it('leaves the flag false when the enclave refuses to enroll', function (): void {
    $user = coldStartVaultUser('cold-start-refuses');
    $vault = new MobileColdStartVault(coldStartEnclave(enrolls: false), app(ColdStartEnrolmentFlag::class));

    expect($vault->enroll((int) $user->id, random_bytes(32)))->toBeFalse()
        ->and($vault->isEnrolled((int) $user->id))->toBeFalse();
});

// The flag is per user: one account enrolling must not light the button up for
// another account on the same device.
it('scopes the enrollment flag per user', function (): void {
    $first = coldStartVaultUser('cold-start-first');
    $second = coldStartVaultUser('cold-start-second');
    $vault = new MobileColdStartVault(coldStartEnclave(), app(ColdStartEnrolmentFlag::class));

    $vault->enroll((int) $first->id, random_bytes(32));

    expect($vault->isEnrolled((int) $first->id))->toBeTrue()
        ->and($vault->isEnrolled((int) $second->id))->toBeFalse();
});

it('returns the key the enclave yields, prompting exactly once', function (): void {
    $user = coldStartVaultUser('cold-start-recovers');
    $dataKey = random_bytes(32);
    $enclave = coldStartEnclave(recovers: $dataKey);
    $vault = new MobileColdStartVault($enclave, app(ColdStartEnrolmentFlag::class));

    expect($vault->recover((int) $user->id, 'Unlock Beatrax'))->toBe($dataKey)
        ->and($enclave->recoveredFor)->toBe([(int) $user->id]);
});

it('returns nothing when the enclave recovery is not completed', function (): void {
    $user = coldStartVaultUser('cold-start-canceled');
    $vault = new MobileColdStartVault(coldStartEnclave(), app(ColdStartEnrolmentFlag::class));

    expect($vault->recover((int) $user->id, 'Unlock Beatrax'))->toBeNull();
});

it('clears both the enclave entry and the flag when forgetting', function (): void {
    $user = coldStartVaultUser('cold-start-forgets');
    $enclave = coldStartEnclave();
    $vault = new MobileColdStartVault($enclave, app(ColdStartEnrolmentFlag::class));

    $vault->enroll((int) $user->id, random_bytes(32));

    expect($vault->forget((int) $user->id))->toBeTrue()
        ->and($enclave->clearedFor)->toBe([(int) $user->id])
        ->and($vault->isEnrolled((int) $user->id))->toBeFalse();
});

// The flag has to come down either way — the reader asked for this off, and an
// enrolment left on keeps offering an unlock they declined. What must not be
// reported is that the enclave gave the key up when it did not.
it('takes the flag down but reports the enclave keeping the key', function (): void {
    $user = coldStartVaultUser('cold-start-refused');
    $enclave = coldStartEnclave();
    $enclave->refusesClear = true;
    $vault = new MobileColdStartVault($enclave, app(ColdStartEnrolmentFlag::class));

    $vault->enroll((int) $user->id, random_bytes(32));

    expect($vault->forget((int) $user->id))->toBeFalse()
        ->and($vault->isEnrolled((int) $user->id))->toBeFalse();
});

// The one refusal that is durable: MISSING means the enclave holds nothing here
// it can read, and an entry a new fingerprint invalidated answers exactly like
// one that was never stored. The flag is this platform's only record of the
// entry, so a flag left standing is a control the screen goes on offering.

it('records the enrolment as gone when the enclave has nothing left to read', function (): void {
    $user = coldStartVaultUser('cold-start-missing');
    $enclave = coldStartEnclave(refusal: BiometricRecoverResult::missing());
    $vault = new MobileColdStartVault($enclave, app(ColdStartEnrolmentFlag::class));

    $vault->enroll((int) $user->id, random_bytes(32));

    expect($vault->recover((int) $user->id, 'Unlock Beatrax'))->toBeNull()
        ->and($vault->isEnrolled((int) $user->id))->toBeFalse();
});

// A refusal that is not about the entry leaves it exactly where it was, or a
// changed mind and a wrong finger each cost a working enrolment.
it('keeps the enrolment through a refusal that is not about the entry', function (BiometricRecoverResult $refusal): void {
    $user = coldStartVaultUser('cold-start-keeps-'.$refusal->status);
    $enclave = coldStartEnclave(refusal: $refusal);
    $vault = new MobileColdStartVault($enclave, app(ColdStartEnrolmentFlag::class));

    $vault->enroll((int) $user->id, random_bytes(32));

    expect($vault->recover((int) $user->id, 'Unlock Beatrax'))->toBeNull()
        ->and($vault->isEnrolled((int) $user->id))->toBeTrue();
})->with([
    'the reader dismissed the sheet' => [fn (): BiometricRecoverResult => BiometricRecoverResult::canceled()],
    'the authentication did not succeed' => [fn (): BiometricRecoverResult => BiometricRecoverResult::failed()],
    'the prompt is still running' => [fn (): BiometricRecoverResult => BiometricRecoverResult::pendingAsync()],
    'there is no runtime to ask' => [fn (): BiometricRecoverResult => BiometricRecoverResult::unavailable()],
]);
