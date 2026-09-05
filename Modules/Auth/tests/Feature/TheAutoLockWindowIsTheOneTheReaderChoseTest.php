<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Auth\Public\Services\AppLockClientConfig;
use Modules\Core\Models\User;

// A desktop reported locking about eight minutes after the last interaction
// with "Auto-lock after" saved as thirty. Three readers stand between the
// column and a lock — the layout's emitted window, the middleware's cached
// config and the idle clock — and this pins all three to the saved value.

function chosenWindowUser(int $minutes): User
{
    $user = User::query()->create([
        'username' => 'auto-lock-'.bin2hex(random_bytes(4)),
        'password' => bcrypt('auto-lock-fixture'),
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);

    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => $minutes,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

    return $user;
}

it('hands the client the window the reader saved and not a default', function (): void {
    $user = chosenWindowUser(30);

    expect(app(AppLockClientConfig::class)->idleTimeoutMs((int) $user->id))->toBe(1_800_000);
});

// The reported measurement, as an assertion: eight minutes of doing nothing
// under a thirty-minute window is not a lock. A reader read at boot, a cached
// five, or a default falling back would each lock the session here.
// Read off the lock flag rather than the status code, because this fixture's
// dashboard redirects for reasons of its own and a 302 says nothing about who
// locked what — the sibling suspended-timer test reads the target for the same
// reason.
it('does not lock eight minutes into a thirty-minute window', function (): void {
    $user = chosenWindowUser(30);

    $response = $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_LAST_ACTIVITY => CarbonImmutable::now()->getTimestamp() - 480,
        ])
        ->get(route('dashboard'));

    expect($response->headers->get('Location'))->not->toBe(route('auth.lock'))
        ->and(session(LockStateManager::SESSION_KEY))->toBeFalse();
});

// The positive control, so the pair cannot both pass by never locking at all.
it('locks once the window the reader chose has actually run out', function (): void {
    $user = chosenWindowUser(30);

    $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_LAST_ACTIVITY => CarbonImmutable::now()->getTimestamp() - 1801,
        ])
        ->get(route('dashboard'))
        ->assertRedirect(route('auth.lock'));

    expect(session(LockStateManager::SESSION_KEY))->toBeTrue();
});

// The second window, and the reason a thirty-minute setting can still be
// followed by a lock minutes later: leaving the foreground arms a thirty-second
// marker that never consults idle_timeout_minutes. Pinned so that "the setting
// is being ignored" and "a different rule fired" stay tellable apart.
it('locks on the background marker whatever window the reader chose', function (): void {
    $user = chosenWindowUser(30);

    $this->actingAs($user)
        ->withSession([
            LockStateManager::SESSION_KEY => false,
            AppLockMiddleware::SESSION_LAST_ACTIVITY => CarbonImmutable::now()->getTimestamp() - 10,
            AppLockMiddleware::SESSION_BACKGROUNDED_AT => CarbonImmutable::now()->getTimestamp() - 31,
        ])
        ->get(route('dashboard'))
        ->assertRedirect(route('auth.lock'));

    expect(session(LockStateManager::SESSION_KEY))->toBeTrue();
});
