<?php

declare(strict_types=1);

// Wave 0 RED — implemented by plan 05-02

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Symfony\Component\HttpFoundation\Response;

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

    // The lock must actually be enabled: a locked session belonging to a user
    // with no lock is released rather than redirected, since no PIN or
    // biometric exists that could ever clear it.
    DB::connection()->table('user_app_lock_configs')->insert([
        'user_id' => $user->id,
        'lock_enabled' => true,
        'idle_timeout_minutes' => 5,
        'failed_attempts' => 0,
        'last_activity_at' => CarbonImmutable::now()->toDateTimeString(),
        'created_at' => CarbonImmutable::now()->toDateTimeString(),
        'updated_at' => CarbonImmutable::now()->toDateTimeString(),
    ]);

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

    // The middleware should NOT redirect to auth.lock when unlocked.
    // The dashboard may redirect elsewhere (e.g. imports.new for a new user) —
    // we only assert that the lock screen redirect does NOT occur.
    $response = $this->withSession([LockStateManager::SESSION_KEY => false])
        ->get(route('dashboard'));

    $lockScreenUrl = route('auth.lock');
    $isRedirectToLock = $response->isRedirection()
        && $response->headers->get('Location') === $lockScreenUrl;
    expect($isRedirectToLock)->toBeFalse('Unlocked session should not redirect to auth.lock');
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

    // Verify the middleware does NOT redirect guests to the lock screen.
    // The middleware short-circuits for unauthenticated requests (isAuthenticated() === false).
    /** @var AppLockMiddleware $middleware */
    $middleware = $this->app->make(AppLockMiddleware::class);

    $request = Request::create(route('login', absolute: false), 'GET');
    $request->setLaravelSession($this->app->make(Session::class));
    $request->session()->put(LockStateManager::SESSION_KEY, true);

    $passed = false;
    $response = $middleware->handle($request, static function () use (&$passed): Response {
        $passed = true;

        return new Response('ok', 200);
    });

    expect($passed)->toBeTrue('Guest request should pass through AppLockMiddleware without redirect');
    expect($response->getStatusCode())->toBe(200);
});
