<?php

declare(strict_types=1);

namespace Modules\Pots\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Wires the Pots module: migrations, routes, views, Livewire component
 * registrations, and the PotBalanceQuery singleton.
 *
 * `PotBalanceQuery` autowires its constructor deps (DatabaseManager /
 * AccountBalanceQuery) — an explicit singleton prevents multiple instances
 * being built per request cycle (same pattern as GoalsServiceProvider).
 *
 * The Livewire PotsPage component is registered here. The route stub
 * (`/pots → PotsPage`) is loaded from Routes/web.php.
 */
final class PotsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 02: $this->app->singleton(\Modules\Pots\Public\Services\PotBalanceQuery::class);
        // Uncommented in Plan 02 once PotBalanceQuery exists. Larastan level 10 flags a
        // missing class in the use-import, so the singleton is deferred per plan instructions.
        // PotWriter is stateless — no singleton needed (same as BudgetWriter)
    }

    public function boot(LivewireManager $livewire): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'pots');
        }

        // Plan 03: $livewire->component('pots.pots-page', PotsPage::class);
        // Registered here once PotsPage exists (same pattern as GoalsServiceProvider
        // which registers GoalsPage after Plan 04).
    }
}
