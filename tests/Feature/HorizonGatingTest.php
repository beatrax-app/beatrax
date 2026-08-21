<?php

declare(strict_types=1);

use App\Providers\HorizonServiceProvider;
use Illuminate\Routing\Router;

uses()->group('Phase14');

// Inspects the live route collection rather than opening a Redis connection,
// so the gate can be proven without a Redis daemon.
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
