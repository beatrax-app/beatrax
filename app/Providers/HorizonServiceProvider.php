<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider as BaseHorizonServiceProvider;

// The single registered Horizon provider: the package's own provider is
// excluded from auto-discovery, so route/asset/event/command registration
// happens exclusively here. The dashboard exposes queue payloads that may
// contain transaction data; gate() plus loopback-only binding guard it.
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

    // Any authenticated user on the loopback dev box may view Horizon;
    // multi-user tightening will revisit this when a second user lands.
    protected function gate(): void
    {
        $gate = $this->app->make(Gate::class);
        $gate->define('viewHorizon', static fn ($user): bool => $user !== null);
    }
}
