<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider as BaseHorizonServiceProvider;

/**
 * Registers Horizon and guards the /horizon dashboard.
 *
 * This is the single registered Horizon provider: the package's own
 * provider is excluded from package auto-discovery so route, asset,
 * event, and command registration happen exclusively through this
 * class. boot() registers the dashboard only when dev mode is on,
 * so a shipped build (dev mode off) exposes no /horizon routes.
 *
 * The dashboard exposes queue payloads, which may contain serialised
 * transaction data. The auth gate requires an authenticated request;
 * loopback-only binding plus Fortify authentication provide the
 * surrounding defence layers.
 */
final class HorizonServiceProvider extends BaseHorizonServiceProvider
{
    public function boot(): void
    {
        // Shipped builds run with dev mode off, so the /horizon routes,
        // assets, events, and commands registered by parent::boot() do
        // not register and the dashboard cannot load.
        if (config('app.dev_mode') !== true) {
            return;
        }

        parent::boot();

        $this->gate();

        Horizon::auth(static fn (Request $request): bool => $request->user() !== null);
    }

    /**
     * Register the Horizon gate.
     *
     * Any authenticated user on the loopback dev box may view Horizon.
     * Multi-user tightening will revisit this when the second user lands.
     */
    protected function gate(): void
    {
        $gate = $this->app->make(Gate::class);
        $gate->define('viewHorizon', static fn ($user): bool => $user !== null);
    }
}
