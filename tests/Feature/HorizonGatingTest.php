<?php

declare(strict_types=1);

use App\Providers\HorizonServiceProvider;
use Illuminate\Routing\Router;

uses()->group('Phase14');

/*
 * SC3 — proves the dev-mode gate on the Horizon dashboard is genuinely
 * effective. App\Providers\HorizonServiceProvider extends the package's
 * route-registering Laravel\Horizon\HorizonServiceProvider and early-exits
 * boot() when config('app.dev_mode') is not exactly true, so a shipped
 * build (dev mode off) registers zero /horizon routes.
 *
 * The assertions inspect the live route collection rather than a Redis
 * connection, so the suite runs without a Redis daemon.
 */

/**
 * Counts routes whose URI or name contains `horizon`, after re-running the
 * provider's boot() against a freshly-set dev-mode config value.
 */
function horizonRouteCount(): int
{
    /** @var Router $router */
    $router = app('router');

    $count = 0;
    foreach ($router->getRoutes() as $route) {
        $uri = $route->uri();
        $name = (string) $route->getName();
        if (str_contains($uri, 'horizon') || str_contains($name, 'horizon')) {
            $count++;
        }
    }

    return $count;
}

it('registers no horizon route when dev mode is off', function (): void {
    config(['app.dev_mode' => false]);

    $provider = new HorizonServiceProvider(app());
    $provider->boot();

    expect(horizonRouteCount())->toBe(
        0,
        'A shipped build (dev mode off) must register zero /horizon routes — the dashboard serialises transaction data.',
    );
});

it('registers the horizon dashboard when dev mode is on', function (): void {
    config(['app.dev_mode' => true]);

    $provider = new HorizonServiceProvider(app());
    $provider->boot();

    expect(horizonRouteCount())->toBeGreaterThan(
        0,
        'With dev mode on the Horizon dashboard must register its routes so the developer can reach /horizon.',
    );
});
