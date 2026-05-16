<?php

declare(strict_types=1);

namespace Modules\Chains\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Chains\Internal\CardStatementStateMachine;

/**
 * Wires the Chains module.
 *
 * The module ships the cross-source funding-chain resolver, the
 * ICS bulk-iDEAL decomposer, and the card_statements lifecycle. The
 * provider currently registers:
 *
 *  - `CardStatementStateMachine` as a singleton — the only legal
 *    mutator of `card_statements.state`. A BoundaryArchTest invariant
 *    blocks any other write path under `Modules/Chains/`.
 *
 * Subsequent plans extend register() with the public read APIs
 * (`ChainLinkQuery`, `CardStatementQuery`) and the public action
 * classes (`ConfirmChainLink`, `RejectChainLink`). boot()
 * conditionally loads migrations / routes / views; is_dir / is_file
 * guards keep the partially-populated skeleton bootable.
 *
 * Public surface is exposed from day one so downstream modules
 * (fixed-payments view, forecasting) can depend on it directly.
 */
final class ChainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CardStatementStateMachine::class);
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
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'chains');
        }
        // Livewire component registrations land here once the
        // chains.chain-review-queue and chains.chain-drawer SFCs ship.
        unset($livewire);
    }
}
