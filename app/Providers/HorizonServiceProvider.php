<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Http\Request;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonServiceProvider as BaseHorizonServiceProvider;
use Modules\Core\Models\User;

// The package's own provider is excluded from auto-discovery, so every
// route, asset, event and command registration happens here or nowhere.
final class HorizonServiceProvider extends BaseHorizonServiceProvider
{
    public function boot(): void
    {
        // Dev mode is off in shipped builds, so parent::boot() never runs
        // and the dashboard has no routes to load.
        if (config('app.dev_mode') !== true) {
            return;
        }

        parent::boot();

        $this->gate();

        Horizon::auth(static function (Request $request): bool {
            $user = $request->user();

            return $user instanceof User && $user->is_developer === true;
        });
    }

    // Queue payloads can carry transaction data, so this is the developer
    // tier rather than any authenticated account.
    protected function gate(): void
    {
        $gate = $this->app->make(Gate::class);
        $gate->define('viewHorizon', static fn ($user): bool => $user instanceof User && $user->is_developer === true);
    }
}
