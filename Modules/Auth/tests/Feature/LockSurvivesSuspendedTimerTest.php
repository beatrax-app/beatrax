<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

/*
 * The app-lock's 30s grace window used to be enforced by a window.setTimeout
 * in lock.js. An Android WebView is suspended while backgrounded, so that
 * timer never fired, and the return handler then called _clearGrace() — which
 * cancelled it regardless of how long the app had actually been away. The
 * phone came back unlocked and only locked once the user touched something.
 *
 * The elapsed time is now judged server-side from a marker written the moment
 * the app leaves the foreground, so no timer has to survive anything.
 */

function suspendedTimerUser(): User
{
    $user = User::query()->create([
        'username' => 'lock-suspend-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('lock-suspend-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return $user;
}

it('locks on the next request when the grace window closed while backgrounded', function (): void {
    $user = suspendedTimerUser();

    // 31s ago: past the grace window, but nowhere near the 5-minute idle
    // timeout — so ONLY the background marker can produce this lock.
    $response = $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_BACKGROUNDED_AT => CarbonImmutable::now()->getTimestamp() - 31,
        ])
        ->get(route('dashboard'));

    $response->assertRedirect(route('auth.lock'));
});

it('does not lock when the app came back inside the grace window', function (): void {
    $user = suspendedTimerUser();

    $response = $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_BACKGROUNDED_AT => CarbonImmutable::now()->getTimestamp() - 5,
        ])
        ->get(route('dashboard'));

    expect($response->headers->get('Location'))->not->toBe(route('auth.lock'));
});

it('spends the marker on the request that reads it', function (): void {
    $user = suspendedTimerUser();

    // A return within grace must not leave the marker behind: backgrounding
    // is what arms it, and a stale one would lock a session that never left.
    $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_BACKGROUNDED_AT => CarbonImmutable::now()->getTimestamp() - 5,
        ])
        ->get(route('dashboard'));

    expect(session()->has(AppLockMiddleware::SESSION_BACKGROUNDED_AT))->toBeFalse();
});

it('records the marker when the client reports backgrounding', function (): void {
    $user = suspendedTimerUser();

    $this->actingAs($user)
        ->withSession([LockStateManager::SESSION_KEY => false])
        ->post(route('auth.lock.background'))
        ->assertNoContent();

    expect(session()->get(AppLockMiddleware::SESSION_BACKGROUNDED_AT))->toBeInt();
});

it('answers the resume probe with the lock redirect lock.js reloads on', function (): void {
    $user = suspendedTimerUser();

    // lock.js reads `response.redirected`, so the redirect IS the signal that
    // the grace closed — the endpoint itself never needs to say so in a body.
    $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_BACKGROUNDED_AT => CarbonImmutable::now()->getTimestamp() - 31,
        ])
        ->post(route('auth.lock.resume'))
        ->assertRedirect(route('auth.lock'));
});
