<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

function appLockConfigUser(string $username): User
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
function insertAppLockConfig(int $userId, array $overrides = []): void
{
    DB::connection()->table('user_app_lock_configs')->insert(array_merge([
        'user_id' => $userId,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ], $overrides));
}

it('passes a locked session through an exempt route when the lock is enabled', function (): void {
    $user = appLockConfigUser('locked-exempt');
    $this->actingAs($user);

    // A real config row, so this is the exempt-route pass-through rather than
    // the null-config release path.
    insertAppLockConfig($user->id);

    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get(route('auth.lock'))
        ->assertOk();
});

it('does not lock an unlocked session whose last activity is null', function (): void {
    $user = appLockConfigUser('unlocked-null-activity');
    $this->actingAs($user);

    insertAppLockConfig($user->id, ['last_activity_at' => null]);

    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('dashboard'));

    $isRedirectToLock = $response->isRedirection()
        && $response->headers->get('Location') === route('auth.lock');

    expect($isRedirectToLock)->toBeFalse('a null last-activity must not trigger an idle lock');
});

it('locks an idle-expired session but still passes through when the route is exempt', function (): void {
    $user = appLockConfigUser('idle-exempt');
    $this->actingAs($user);

    // Unlocked but long idle, on the exempt route: the session must lock and
    // still render, rather than redirect into a loop.
    insertAppLockConfig($user->id, [
        'idle_timeout_minutes' => 1,
        'last_activity_at' => CarbonImmutable::now()->subHours(2)->toDateTimeString(),
    ]);

    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('auth.lock'))
        ->assertOk()
        ->assertSessionHas(LockStateManager::SESSION_KEY, true);
});
