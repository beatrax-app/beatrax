<?php

declare(strict_types=1);

namespace Modules\Chains\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;

/**
 * Wires the Chains module.
 *
 * The module ships the cross-source funding-chain resolver, the
 * ICS bulk-iDEAL decomposer, and the card_statements lifecycle. This
 * provider is intentionally minimal at module-skeleton time:
 *
 *  - register() will gain singleton bindings for the public read APIs
 *    (`ChainLinkQuery`, `CardStatementQuery`) and the public action
 *    classes (`ConfirmChainLink`, `RejectChainLink`) in a subsequent
 *    plan.
 *  - boot() conditionally loads migrations / routes / views once those
 *    artefacts exist. The is_dir / is_file guards keep the empty
 *    skeleton bootable.
 *  - Livewire component registration moves into boot() once the
 *    `chains.chain-review-queue` and `chains.chain-drawer` components
 *    are ready.
 *
 * Public surface is exposed from day one so downstream modules
 * (fixed-payments view, forecasting) can depend on it directly.
 */
final class ChainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Concrete singleton bindings land alongside the resolver,
        // state-machine, and Livewire components in subsequent plans.
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
