<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Modules\Core\Models\User;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureDatabaseReady;
use Modules\Mobile\Internal\Http\Middleware\MobileEnsureImportCompleted;
use Modules\Mobile\Internal\Sync\MobileImportIntentGate;
use Symfony\Component\HttpFoundation\Response;

uses(RefreshDatabase::class);

/** @return list<string> the route names every mobile gate must let through */
function publicArtefactRouteNames(): array
{
    return ['site.webmanifest', 'pwa.icon', 'app.icon', 'app.splash'];
}

// Driven through the middleware rather than grepped out of its source: a route
// name sitting in a comment or an unread constant satisfies a grep just as well.
function artefactRequestNamed(string $routeName): Request
{
    $request = Request::create('/icon.png', 'GET');

    $route = new RoutingRoute(['GET'], '/icon.png', ['as' => $routeName]);
    $route->name($routeName);
    $request->setRouteResolver(static fn (): RoutingRoute => $route);

    return $request;
}

// The manifest and the icon set are fetched by the WebView itself, and no web
// server sits in front of PHP on a phone, so a gate that catches them answers an
// image request with a page of HTML. MobileEnsureImportCompleted did not exempt
// them, and that is the gate running during setup.

it('lets the public artefacts past the import gate while a setup is unfinished', function (): void {
    $user = User::query()->create([
        'username' => 'artefact-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    // The exact state that redirected everything on the device.
    app(MobileImportIntentGate::class)->markImporting((int) $user->id);

    $gate = app(MobileEnsureImportCompleted::class);

    foreach (publicArtefactRouteNames() as $name) {
        $reached = false;
        $response = $gate->handle(
            artefactRequestNamed($name),
            function () use (&$reached): Response {
                $reached = true;

                return new Response('PNG', 200, ['Content-Type' => 'image/png']);
            },
        );

        expect($reached)->toBeTrue("the import gate intercepted {$name}")
            ->and($response->isRedirection())->toBeFalse("the import gate redirected {$name}");
    }
});

it('redirects an ordinary route in that same state', function (): void {
    $user = User::query()->create([
        'username' => 'artefact-gated-'.bin2hex(random_bytes(4)),
        'password' => 'fixture',
        'period_start_day' => 1,
        'default_currency_view' => 'eur_only',
    ]);
    test()->actingAs($user);

    app(MobileImportIntentGate::class)->markImporting((int) $user->id);

    // Without this the test above would pass on a gate that never redirects.
    $response = app(MobileEnsureImportCompleted::class)->handle(
        artefactRequestNamed('dashboard'),
        static fn (): Response => new Response('page', 200),
    );

    expect($response->isRedirection())->toBeTrue();
});

it('lets them past the database-ready gate too', function (): void {
    $gate = app(MobileEnsureDatabaseReady::class);

    foreach (publicArtefactRouteNames() as $name) {
        $reached = false;
        $gate->handle(artefactRequestNamed($name), function () use (&$reached): Response {
            $reached = true;

            return new Response('PNG', 200, ['Content-Type' => 'image/png']);
        });

        expect($reached)->toBeTrue("the database-ready gate intercepted {$name}");
    }
});

it('still names those routes in the router', function (): void {
    // The exemption is by route name, so a rename would silently un-exempt
    // them — the failure this test exists to catch.
    $routes = (string) file_get_contents(base_path('routes/web.php'));

    foreach (['app.icon', 'app.splash', 'pwa.icon'] as $name) {
        expect($routes)->toContain("name('".$name."')");
    }
});
