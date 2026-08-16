<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Core\Models\User;

/*
 * The idle timer locks from JAVASCRIPT and navigates straight to the lock
 * screen, so the middleware never sees the request that would have set
 * `url.intended` — and every idle unlock dropped the user on the dashboard
 * instead of the page they were reading.
 */

it('remembers the page a user was on while unlocked', function (): void {
    $user = User::query()->create([
        'username' => 'restore-user',
        'password' => bcrypt('restore-pass'),
        'period_start_day' => 1,
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

    $this->actingAs($user)->get('/transactions')->assertSuccessful();

    expect(session(AppLockMiddleware::SESSION_LAST_PAGE))->toContain('/transactions');
});

it('does not remember the lock screen itself as a place to return to', function (): void {
    $user = User::query()->create([
        'username' => 'restore-exempt-user',
        'password' => bcrypt('restore-pass'),
        'period_start_day' => 1,
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

    $this->actingAs($user)->get(route('auth.lock'));

    // Returning to the lock screen after unlocking it would be a loop.
    expect(session(AppLockMiddleware::SESSION_LAST_PAGE))->toBeNull();
});
