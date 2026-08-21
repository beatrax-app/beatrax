<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Auth\Internal\Http\Middleware\AppLockMiddleware;
use Modules\Auth\Internal\Lock\LockStateManager;
use Modules\Core\Models\User;
use Symfony\Component\HttpFoundation\Response;

it('AppLockMiddleware class exists (RED until 05-02)', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();
});

it('redirects to auth.lock when the session is locked', function (): void {
    expect(class_exists(AppLockMiddleware::class))->toBeTrue();

    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
    ]);
    $this->actingAs($user);

    // The lock has to be genuinely enabled: a locked session whose user has no
    // lock is released rather than redirected, since nothing could clear it.
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

    // The dashboard may still redirect elsewhere for a new user, so only the
    // lock-screen redirect is asserted against.
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
