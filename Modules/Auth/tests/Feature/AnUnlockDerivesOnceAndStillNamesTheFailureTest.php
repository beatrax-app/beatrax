<?php

declare(strict_types=1);

// The correct PIN used to be stretched twice — once to verify the PIN hash,
// once to derive the wrap key — for one unlock. The unwrap authenticates on
// its own, so the hash is now consulted only where the unwrap failed, and only
// to say whether the blob is corrupt or the PIN wrong. Both answers are pinned
// here, because collapsing them either way is the way this goes wrong: a wrong
// PIN that raises a critical corruption alert, or a corrupt blob that silently
// counts as a wrong PIN.

use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Auth\Internal\Lock\AppLockProvisioner;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Internal\Lock\PinVerificationService;
use Modules\Auth\Tests\Support\CountingKdfCost;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Models\User;

function unlockCostUser(string $username): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    test()->actingAs($user);

    return $user;
}

function unlockCostRow(User $user): stdClass
{
    /** @var stdClass $row */
    $row = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->first();

    return $row;
}

it('unlocks a correct PIN over a hash that could never verify, and derives the wrap key once to do it', function (): void {
    $counter = CountingKdfCost::install();
    $user = unlockCostUser('one-derivation');

    app(AppLockProvisioner::class)->enable($user->id, '123456', 'whatever-password');

    // Ruined rather than merely uncounted: a hash nothing can verify makes the
    // unlock provably independent of it, which no counter can show, since
    // libsodium reads that cost out of the hash string and not the contract.
    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['pin_hash' => 'not-a-hash-at-all']);

    /** @var Session $session */
    $session = app(Session::class);
    app(LockStateManager::class)->lock($session);

    // Only the unlock is measured: enabling the lock derives three times.
    $counter->derivations = 0;

    $dataKey = app(PinVerificationService::class)->verify($user->id, '123456', $session)->dataKey;

    expect($dataKey)->toBeString('The unwrap authenticates the PIN, so the hash beside it is not consulted.')
        ->and(app(LockStateManager::class)->isLocked($session))->toBeFalse()
        ->and($counter->derivations)->toBe(
            1,
            'One wrap key, derived once. A second means the unwrap is being repeated rather than carried to the write.',
        );
});

it('counts a wrong PIN over a corrupt blob as a wrong PIN and raises no corruption alert', function (): void {
    $user = unlockCostUser('wrong-pin-corrupt-blob');

    app(AppLockProvisioner::class)->enable($user->id, '123456', 'whatever-password');

    // The PIN hash is left intact, so it is the only thing that can still say
    // this attempt was a wrong PIN rather than a corrupted key.
    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['pin_wrapped_key' => base64_encode(random_bytes(60))]);

    /** @var Session $session */
    $session = app(Session::class);

    expect(app(PinVerificationService::class)->verify($user->id, '000000', $session)->dataKey)->toBeNull();

    expect(SystemAlert::query()->where('user_id', $user->id)->where('kind', 'auth.lock.corrupted_key')->count())
        ->toBe(0, 'A wrong PIN must never be reported to the reader as a corrupted key.');

    expect((int) unlockCostRow($user)->failed_attempts)->toBe(1);
});

it('still reaches the critical alert for a correct PIN over a corrupt blob, and counts no failure', function (): void {
    $user = unlockCostUser('right-pin-corrupt-blob');

    app(AppLockProvisioner::class)->enable($user->id, '123456', 'whatever-password');

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['pin_wrapped_key' => base64_encode(random_bytes(60))]);

    /** @var Session $session */
    $session = app(Session::class);

    expect(app(PinVerificationService::class)->verify($user->id, '123456', $session)->dataKey)->toBeNull();

    $alert = SystemAlert::query()
        ->where('user_id', $user->id)
        ->where('kind', 'auth.lock.corrupted_key')
        ->first();

    expect($alert)->not->toBeNull()
        ->and($alert->severity)->toBe('critical');

    expect((int) unlockCostRow($user)->failed_attempts)->toBe(
        0,
        'A corrupted key wrap is a non-counting failure: it must not walk the reader towards a sign-out.',
    );
});

it('alerts rather than crashing when the stored salt is a length libsodium refuses', function (): void {
    $user = unlockCostUser('unusable-salt');

    app(AppLockProvisioner::class)->enable($user->id, '123456', 'whatever-password');

    DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->update(['kdf_salt' => random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES - 1)]);

    /** @var Session $session */
    $session = app(Session::class);

    expect(app(PinVerificationService::class)->verify($user->id, '123456', $session)->dataKey)->toBeNull()
        ->and(SystemAlert::query()->where('user_id', $user->id)->where('kind', 'auth.lock.corrupted_key')->count())->toBe(1);

    expect(app(PinVerificationService::class)->verify($user->id, '000000', $session)->dataKey)->toBeNull()
        ->and((int) unlockCostRow($user)->failed_attempts)->toBe(1, 'A wrong PIN over an unusable salt is still a wrong PIN.');
});

// The derivation now runs before the write transaction opens, so a PIN change
// can commit underneath it. Acting on the stale answer would either unlock
// with a key the row no longer wraps or count a failure against a PIN nobody
// typed, so the attempt records nothing and the reader simply tries again.
it('records nothing when a PIN change commits between the read it derived against and its write', function (): void {
    $user = unlockCostUser('rewrapped-mid-attempt');

    app(AppLockProvisioner::class)->enable($user->id, '123456', 'whatever-password');

    /** @var Session $session */
    $session = app(Session::class);
    app(LockStateManager::class)->lock($session);

    $rewrapped = false;
    Event::listen(function (QueryExecuted $event) use (&$rewrapped, $user): void {
        if ($rewrapped || ! str_contains($event->sql, 'user_app_lock_configs')) {
            return;
        }
        if (! str_starts_with(strtolower($event->sql), 'select')) {
            return;
        }

        $rewrapped = true;
        DB::connection()->table('user_app_lock_configs')
            ->where('user_id', $user->id)
            ->update(['pin_wrapped_key' => base64_encode(random_bytes(60))]);
    });

    $result = app(PinVerificationService::class)->verify($user->id, '123456', $session);

    expect($rewrapped)->toBeTrue()
        ->and($result->dataKey)->toBeNull('The key it unwrapped is not the one this row wraps any more.')
        ->and($result->pinChangedMidAttempt)->toBeTrue('A screen handed a bare null tells the reader the PIN was wrong.')
        ->and(app(LockStateManager::class)->isLocked($session))->toBeTrue();

    expect((int) unlockCostRow($user)->failed_attempts)->toBe(
        0,
        'The reader typed the right PIN; losing a race with a re-wrap must not walk them towards a sign-out.',
    );
});
