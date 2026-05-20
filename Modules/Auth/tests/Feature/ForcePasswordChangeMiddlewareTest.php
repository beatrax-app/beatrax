<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Route as RoutingRoute;
use Modules\Auth\Internal\Http\Middleware\ForcePasswordChangeMiddleware;
use Modules\Core\Models\User;
use Symfony\Component\HttpFoundation\Response;

/*
 * Feature coverage for ForcePasswordChangeMiddleware: a request passes
 * through when the user's force_password_change_at_next_login flag is
 * false, is redirected to /change-password when the flag is true and the
 * route is not exempt, and is NOT redirected when the route IS the
 * change-password page or the logout route (so no redirect loop forms).
 */

/**
 * Builds a Request bound to a named route so the middleware can read the
 * route name.
 */
function requestNamed(string $routeName, string $uri = '/'): Request
{
    $request = Request::create($uri);
    $route = new RoutingRoute(['GET'], $uri, []);
    $route->name($routeName);
    $request->setRouteResolver(static fn (): RoutingRoute => $route);

    return $request;
}

it('passes a request through when the flag is false', function (): void {
    $user = User::query()->create([
        'username' => 'alice',
        'password' => 'whatever-password',
        'period_start_day' => 1,
        'force_password_change_at_next_login' => false,
    ]);
    $this->actingAs($user);

    /** @var ForcePasswordChangeMiddleware $middleware */
    $middleware = $this->app->make(ForcePasswordChangeMiddleware::class);

    $response = $middleware->handle(requestNamed('dashboard'), static fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes a request through when no user is authenticated', function (): void {
    /** @var ForcePasswordChangeMiddleware $middleware */
    $middleware = $this->app->make(ForcePasswordChangeMiddleware::class);

    $response = $middleware->handle(requestNamed('login', '/login'), static fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('redirects to /change-password when the flag is true and the route is not exempt', function (): void {
    $user = User::query()->create([
        'username' => 'partner',
        'password' => 'whatever-password',
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);
    $this->actingAs($user);

    /** @var ForcePasswordChangeMiddleware $middleware */
    $middleware = $this->app->make(ForcePasswordChangeMiddleware::class);

    $response = $middleware->handle(requestNamed('dashboard'), static fn (): Response => new Response('ok'));

    expect($response)->toBeInstanceOf(RedirectResponse::class);
    expect($response->headers->get('Location'))->toBe(route('auth.change-password'));
});

it('does not redirect when the route is the change-password page', function (): void {
    $user = User::query()->create([
        'username' => 'partner',
        'password' => 'whatever-password',
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);
    $this->actingAs($user);

    /** @var ForcePasswordChangeMiddleware $middleware */
    $middleware = $this->app->make(ForcePasswordChangeMiddleware::class);

    $response = $middleware->handle(
        requestNamed('auth.change-password', '/change-password'),
        static fn (): Response => new Response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('does not redirect when the route is the logout route', function (): void {
    $user = User::query()->create([
        'username' => 'partner',
        'password' => 'whatever-password',
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);
    $this->actingAs($user);

    /** @var ForcePasswordChangeMiddleware $middleware */
    $middleware = $this->app->make(ForcePasswordChangeMiddleware::class);

    $response = $middleware->handle(
        requestNamed('logout', '/logout'),
        static fn (): Response => new Response('ok'),
    );

    expect($response->getContent())->toBe('ok');
});

it('redirects a flagged user away from the dashboard and back once the flag clears', function (): void {
    $user = User::query()->create([
        'username' => 'partner',
        'password' => 'whatever-password',
        'period_start_day' => 1,
        'force_password_change_at_next_login' => true,
    ]);

    $this->actingAs($user)->get('/')->assertRedirect(route('auth.change-password'));

    User::query()->where('id', $user->id)->update(['force_password_change_at_next_login' => false]);

    $this->actingAs($user->fresh())->get('/')->assertOk();
});
