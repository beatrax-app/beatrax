<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

/**
 * Registers Horizon and guards the /horizon dashboard.
 *
 * The dashboard exposes queue payloads, which may contain serialised
 * transaction data. The auth gate requires an authenticated request;
 * loopback-only binding plus Fortify authentication provide the
 * surrounding defence layers.
 */
final class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    public function boot(): void
    {
        parent::boot();

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
