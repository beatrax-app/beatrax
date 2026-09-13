<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\ColdStartEnroller;
use Modules\Auth\Internal\Lock\ColdStartEnrollmentResult;
use Modules\Auth\Public\Contracts\ColdStartVault;
use Modules\Auth\Public\Services\MobileLockGateway;
use Modules\Auth\Tests\Support\DurableColdStartVault;
use Modules\Core\Models\User;

uses(RefreshDatabase::class);

// The OS vault is a durable wrap of the data key that a biometric alone opens
// afterwards. Everything below is about the one price of creating one: the PIN,
// typed now, verified now, and turned into the very key that gets stored.

function coldStartEnrollmentUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => bcrypt('enrolment-password'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    app(AppLockProvisioner::class)->enable((int) $user->id, '123456', 'enrolment-password');

    return $user;
}

function bindEnrollmentVault(ColdStartVault $vault): ColdStartEnroller
{
    app()->instance(ColdStartVault::class, $vault);

    return app(ColdStartEnroller::class);
}

it('enrols with the correct PIN, wrapping the key that PIN unwrapped', function (): void {
    $user = coldStartEnrollmentUser('enrol-ok');
    $vault = new DurableColdStartVault;

    $result = bindEnrollmentVault($vault)->enroll((int) $user->id, '123456', app(Session::class));

    expect($result)->toBe(ColdStartEnrollmentResult::Enrolled)
        ->and($vault->keys[$user->id] ?? null)->toBeString()
        ->and(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeTrue();
});

it('stores nothing on a wrong PIN and leaves the flag down', function (): void {
    $user = coldStartEnrollmentUser('enrol-wrong-pin');
    $vault = new DurableColdStartVault;

    $result = bindEnrollmentVault($vault)->enroll((int) $user->id, '000000', app(Session::class));

    expect($result)->toBe(ColdStartEnrollmentResult::PinRejected)
        ->and($vault->keys)->toBe([])
        ->and(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse();
});

// An empty box is a wrong PIN here rather than a special case: the funnel has
// no branch that skips verification, which is what makes it a funnel.
it('stores nothing when no PIN is offered at all', function (): void {
    $user = coldStartEnrollmentUser('enrol-empty-pin');
    $vault = new DurableColdStartVault;

    $result = bindEnrollmentVault($vault)->enroll((int) $user->id, '', app(Session::class));

    expect($result)->toBe(ColdStartEnrollmentResult::PinRejected)
        ->and($vault->keys)->toBe([]);
});

// The same meter the lock screen counts against, so guessing at the enrolment
// box is not an unmetered oracle for the PIN.
it('spends a PIN attempt on a wrong guess, sharing the lock-screen backoff', function (): void {
    $user = coldStartEnrollmentUser('enrol-consumes-attempt');
    $before = app(MobileLockGateway::class)->remainingPinAttempts((int) $user->id);

    bindEnrollmentVault(new DurableColdStartVault)->enroll((int) $user->id, '000000', app(Session::class));

    expect(app(MobileLockGateway::class)->remainingPinAttempts((int) $user->id))->toBeLessThan($before);
});

it('does not verify a PIN at all where the platform has no vault to arm', function (): void {
    $user = coldStartEnrollmentUser('enrol-unavailable');
    $before = app(MobileLockGateway::class)->remainingPinAttempts((int) $user->id);

    $result = app(ColdStartEnroller::class)->enroll((int) $user->id, '123456', app(Session::class));

    expect($result)->toBe(ColdStartEnrollmentResult::VaultRefused)
        ->and(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse()
        ->and(app(MobileLockGateway::class)->remainingPinAttempts((int) $user->id))->toBe($before);
});

// A refused write must not leave the flag up: the lock screen reads the flag to
// decide whether to offer the unlock, and an unlock offered over nothing is a
// dead button on the one screen the reader cannot get past.
it('leaves the flag down when the platform refuses to hold the key', function (): void {
    $user = coldStartEnrollmentUser('enrol-refused');

    $vault = new class implements ColdStartVault
    {
        public function isAvailable(): bool
        {
            return true;
        }

        public function isEnrolled(int $userId): bool
        {
            return false;
        }

        public function enroll(int $userId, string $dataKey): bool
        {
            return false;
        }

        public function recover(int $userId, string $reason): ?string
        {
            return null;
        }

        public function forget(int $userId): bool
        {
            return true;
        }
    };

    $result = bindEnrollmentVault($vault)->enroll((int) $user->id, '123456', app(Session::class));

    expect($result)->toBe(ColdStartEnrollmentResult::VaultRefused)
        ->and(app(MobileLockGateway::class)->isColdStartEnrolled((int) $user->id))->toBeFalse();
});
