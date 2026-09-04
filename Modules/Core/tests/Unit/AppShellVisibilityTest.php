<?php

declare(strict_types=1);

use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Modules\Core\Public\Contracts\CurrentUser;
use Modules\Core\Public\Support\AppShellVisibility;

// The two answers this class has to get right when it cannot see a route are
// opposites of each other, and both are reachable: a console render has no
// Request bound at all, and a stubbed container hands back something that is
// not one. Neither may take the menubar off a page inside the application.

function appShellReader(bool $signedIn): CurrentUser
{
    $currentUser = test()->createStub(CurrentUser::class);
    $currentUser->method('isAuthenticated')->willReturn($signedIn);

    return $currentUser;
}

function appShellOn(?string $routeName, bool $signedIn = true): AppShellVisibility
{
    $container = test()->createStub(Container::class);
    $container->method('bound')->willReturn(true);

    $route = new Route(['GET'], '/probe', []);

    if ($routeName !== null) {
        $route->name($routeName);
    }

    // A real Request, not a double: PHPUnit refuses to double any class with a
    // method called `method`, and Request has one.
    $request = Request::create('/probe');
    $request->setRouteResolver(static fn (): Route => $route);
    $container->method('make')->willReturn($request);

    return new AppShellVisibility(appShellReader($signedIn), $container);
}

it('draws the shell on a route that is a page of the application', function (): void {
    expect(appShellOn('transactions.index')->visible())->toBeTrue();
});

it('withholds it on a route the reader passes through before the app is theirs', function (string $routeName): void {
    expect(appShellOn($routeName)->visible())->toBeFalse();
})->with([
    'auth.recovery-codes-display',
    'auth.change-password',
    'desktop.setup',
    'mobile.import',
]);

it('withholds it from a reader who is not signed in at all', function (): void {
    expect(appShellOn('transactions.index', signedIn: false)->visible())->toBeFalse();
});

it('draws it where the route carries no name to match on', function (): void {
    expect(appShellOn(null)->visible())->toBeTrue();
});

it('draws it where nothing has bound a request', function (): void {
    $container = test()->createStub(Container::class);
    $container->method('bound')->willReturn(false);

    expect((new AppShellVisibility(appShellReader(true), $container))->visible())->toBeTrue();
});

it('draws it where the container hands back something that is not a request', function (): void {
    $container = test()->createStub(Container::class);
    $container->method('bound')->willReturn(true);
    $container->method('make')->willThrowException(new RuntimeException('no request in a console render'));

    expect((new AppShellVisibility(appShellReader(true), $container))->visible())->toBeTrue();
});
