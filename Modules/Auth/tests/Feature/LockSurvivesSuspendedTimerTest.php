<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

// The grace window was a window.setTimeout in lock.js, and a backgrounded
// Android WebView is suspended, so it never fired and the return handler
// cancelled it however long the app was away. A marker needs no timer.

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

    // Past the grace window but nowhere near the idle timeout, so only the
    // background marker can produce this lock.
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

    // Backgrounding is what arms the marker, and a stale one would lock a
    // session that never left.
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

    // lock.js reads `response.redirected`, so the redirect is itself the signal
    // that the grace window closed.
    $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_BACKGROUNDED_AT => CarbonImmutable::now()->getTimestamp() - 31,
        ])
        ->post(route('auth.lock.resume'))
        ->assertRedirect(route('auth.lock'));
});
