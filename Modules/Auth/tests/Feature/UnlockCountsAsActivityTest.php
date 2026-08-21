<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockKeyService;
use Modules\Core\Models\User;

// Only the PIN path stamped `last_activity_at`, so a biometric unlock left the
// row idle and the next request locked again. Pinned at
// LockStateManager::unlock() because one of its three callers remembered.
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
        // Old enough that the idle check fires — the state the row is in when
        // an idle lock sends the user to the PIN pad.
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

    // The vault path: the enclave hands back the data key and the session opens
    // with it, never touching the config row.
    $keyService->withhold($session);
    $keyService->admitDataKey($session, str_repeat("\x11", 32));

    // Asserted as "not bounced to the lock screen" rather than a 200: this pins
    // the middleware's decision, not whether the page behind it renders.
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
