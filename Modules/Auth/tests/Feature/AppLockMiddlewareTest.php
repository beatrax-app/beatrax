<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-02

use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;

/*
 * Feature coverage for AppLockMiddleware: gates every authenticated route
 * behind the app-lock screen when the session is locked. The lock screen
 * and logout routes are exempt to prevent redirect loops.
 *
 * These tests go GREEN when plan 05-02 creates AppLockMiddleware and the
 * auth.lock named route.
 */

it('AppLockMiddleware class exists (RED until 05-02)', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();
});

it('redirects to auth.lock when the session is locked', function (): void {
    // Requires: AppLockMiddleware class + auth.lock route from 05-02
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    /** @var AppLockMiddleware $middleware */
    $middleware = $this->app->make(AppLockMiddleware::class);

    $response = $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get(route('dashboard'));

    $response->assertRedirect(route('auth.lock'));
});

it('passes through when the session is unlocked', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'bob',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('dashboard'))
        ->assertOk();
});

it('passes through when the route is auth.lock (exempt)', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'carol',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get(route('auth.lock'))
        ->assertOk();
});

it('passes through when the route is logout (exempt)', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'dan',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->post(route('logout'))
        ->assertRedirect();
});

it('passes through for guests (unauthenticated requests are not locked)', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $this->withSession([LockStateManager::SESSION_KEY => true])
        ->get(route('login'))
        ->assertOk();
});
