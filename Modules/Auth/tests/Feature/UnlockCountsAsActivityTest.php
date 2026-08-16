<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;

/*
 * Unlocking counts as activity, whichever way it was done.
 *
 * The bug: only the PIN path stamped `last_activity_at`. A biometric or
 * OS-vault unlock left the config row reading exactly as idle as it had been
 * a moment earlier, so the next request's idle check fired, the middleware
 * locked the session again, and the user was asked to unlock a second time
 * having just proved presence. The same stale row also defeated
 * LockEngageController's grace window, so an in-flight idle-timer POST could
 * re-lock behind a successful unlock.
 *
 * Pinned at LockStateManager::unlock() rather than per path: three callers
 * reach it — PIN, the OS-gated vault, and WebAuthn — and the failure was one
 * of them remembering and the others not.
 */
function idleUnlockUser(string $username, int $idleMinutesAgo): User
{
    $user = User::query()->create([
        'username' => $username,
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);

    $now = CarbonImmutable::now();

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        // Old enough that the idle check fires: exactly the state the config
        // row is in at the moment an idle lock sends the user to the PIN pad.
        'last_activity_at' => $now->subMinutes($idleMinutesAgo)->toDateTimeString(),
        'created_at' => $now->toDateTimeString(),
        'updated_at' => $now->toDateTimeString(),
    ]);

    return $user;
}

it('does not send the user back to the lock screen after a vault unlock', function (): void {
    $user = idleUnlockUser('vault-unlock-'.bin2hex(random_bytes(3)), idleMinutesAgo: 30);
    $this->actingAs($user);

    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    // The OS-gated vault path: the enclave handed back the data key and the
    // session was opened with it, without ever touching the config row.
    $keyService->withhold($session);
    $keyService->admitDataKey($session, str_repeat("\x11", 32));

    // Asserted as "not bounced to the lock screen" rather than a 200: what
    // this pins is the middleware's decision, and whether the page behind it
    // renders on a bare fixture database is a different question entirely.
    $response = $this->withSession($session->all())->get(route('dashboard'));

    expect($response->headers->get('Location'))->not->toBe(route('auth.lock'));
});

it('records the unlock against the config row the engage grace window reads', function (): void {
    $user = idleUnlockUser('engage-grace-'.bin2hex(random_bytes(3)), idleMinutesAgo: 30);
    $this->actingAs($user);

    /** @var AppLockKeyService $keyService */
    $keyService = $this->app->make(AppLockKeyService::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $keyService->withhold($session);
    $keyService->admitDataKey($session, str_repeat("\x22", 32));

    $this->withSession($session->all())->get(route('dashboard'));

    $lastActivity = DB::connection()->table('user_app_lock_configs')
        ->where('user_id', $user->id)
        ->value('last_activity_at');

    expect($lastActivity)->toBeString()
        ->and(CarbonImmutable::parse((string) $lastActivity)->diffInMinutes(CarbonImmutable::now(), absolute: true))
        ->toBeLessThan(1);
});

it('does not credit a re-locked session with the unlock it just undid', function (): void {
    /** @var LockStateManager $lockState */
    $lockState = $this->app->make(LockStateManager::class);
    /** @var Session $session */
    $session = $this->app->make(Session::class);

    $lockState->unlock($session, str_repeat("\x33", 32));

    expect($session->get(LockStateManager::SESSION_UNLOCK_ACTIVITY_PENDING))->toBeTrue();

    $lockState->lock($session);

    expect($session->has(LockStateManager::SESSION_UNLOCK_ACTIVITY_PENDING))->toBeFalse();
});
