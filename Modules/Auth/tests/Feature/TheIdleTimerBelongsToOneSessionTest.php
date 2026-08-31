<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

// Two sessions of one account is the ordinary shape here: the desktop bundle
// and a browser tab. `last_activity_at` is a single column keyed on user_id,
// so every session wrote it and every session read it.

function idleTimerUser(string $username): User
{
    return User::query()->create([
        'username' => $username,
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function idleTimerConfig(int $userId, array $overrides = []): void
{
    DB::connection()->table('user_app_lock_configs')->insert(array_merge([
        'user_id' => $userId,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 1,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ], $overrides));
}

it('locks an idle session even while another session of the same account is being used', function (): void {
    $user = idleTimerUser('idle-second-session');
    $this->actingAs($user);

    // The row says the account was active a moment ago, because the OTHER
    // session polled. This session has been untouched for eighty seconds
    // against a one-minute window.
    idleTimerConfig($user->id, ['last_activity_at' => CarbonImmutable::now()->toDateTimeString()]);

    $this->withSession([
        LockStateManager::SESSION_KEY => false,
        AppLockMiddleware::SESSION_LAST_ACTIVITY => CarbonImmutable::now()->subSeconds(80)->getTimestamp(),
    ])->get(route('dashboard'))->assertRedirect(route('auth.lock'));
});

it('leaves a session that was just used unlocked even when the shared row reads stale', function (): void {
    $user = idleTimerUser('active-second-session');
    $this->actingAs($user);

    idleTimerConfig($user->id, ['last_activity_at' => CarbonImmutable::now()->subHours(2)->toDateTimeString()]);

    $response = $this->withSession([
        LockStateManager::SESSION_KEY => false,
        AppLockMiddleware::SESSION_LAST_ACTIVITY => CarbonImmutable::now()->getTimestamp(),
    ])->get(route('dashboard'));

    $wentToLock = $response->isRedirection()
        && $response->headers->get('Location') === route('auth.lock');

    expect($wentToLock)->toBeFalse('this session proved presence a moment ago');
});

it('stamps the session with its own activity so the next request has a clock to read', function (): void {
    $user = idleTimerUser('stamps-its-own-clock');
    $this->actingAs($user);

    idleTimerConfig($user->id);

    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('dashboard'))
        ->assertSessionHas(AppLockMiddleware::SESSION_LAST_ACTIVITY);
});
