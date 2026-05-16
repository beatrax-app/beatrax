<?php

declare(strict_types=1);

namespace Modules\Chains\Providers;

use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Chains\Internal\CardStatementStateMachine;
use Modules\Chains\Internal\ChainLinkInsertHelper;
use Modules\Chains\Internal\Resolvers\IcsSettlementResolver;
use Modules\Chains\Internal\Resolvers\PaypalFundingResolver;

/**
 * Wires the Chains module.
 *
 * Wave 2 (this state) registers the resolver + helper singletons on
 * top of Wave 1's state-machine singleton:
 *
 *  - `CardStatementStateMachine` — the only legal mutator of
 *    `card_statements.state` (D-95 / BoundaryArchTest invariant).
 *  - `ChainLinkInsertHelper` — shared chain_links INSERT site with
 *    consistent JSON encoding (issue #4 fix).
 *  - `IcsSettlementResolver` — ASN→ICS bulk-iDEAL decomposer
 *    (Pattern 4, RESEARCH lines 696-757).
 *  - `PaypalFundingResolver` — Wave 2 stub; Wave 3 ships the real
 *    deterministic + fuzzy PayPal funding resolver.
 *
 * A follow-up Wave 2 commit adds the queued `ResolveChainLinksJob`
 * binding plus the `JobFailed` event listener that flips
 * `chain_resolution_runs.status='failed'` on final-retry exhaustion.
 *
 * Subsequent plans extend register() with the public read APIs
 * (`ChainLinkQuery`, `CardStatementQuery`) and the public action
 * classes (`ConfirmChainLink`, `RejectChainLink`).
 */
final class ChainsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CardStatementStateMachine::class);
        $this->app->singleton(ChainLinkInsertHelper::class);
        $this->app->singleton(IcsSettlementResolver::class);
        $this->app->singleton(PaypalFundingResolver::class);
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
