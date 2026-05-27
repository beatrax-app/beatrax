<?php

declare(strict_types=1);

namespace Modules\Counterparties\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;
use Modules\Counterparties\Internal\Pipeline\ResolveCounterpartyStage;
use Modules\Counterparties\Internal\Resolver\CounterpartyResolverService;
use Modules\Counterparties\Public\Contracts\CounterpartyResolver;
use Modules\Counterparties\Public\Pipeline\ResolvesCounterparties;

/**
 * Wires the Counterparties module:
 *
 *  - binds the `CounterpartyResolver` Public contract to its default
 *    `CounterpartyResolverService` implementation as a singleton —
 *    cross-module consumers (ImportPipeline, Ledger, Recurring,
 *    Chains, Categorization) inject the interface and share one
 *    instance per request / job.
 *  - binds the `ResolvesCounterparties` Public pipeline contract to
 *    its `ResolveCounterpartyStage` implementation. ImportPipeline
 *    consumes the contract so the Public/Internal boundary stays
 *    intact — the per-module arch invariant blocks any reach from
 *    `Modules\Import` into `Modules\Counterparties\Internal`.
 *  - loads the module's migrations so `counterparties` + the
 *    additive `transactions.counterparty_id` column materialise on
 *    `php artisan migrate`.
 *  - loads the module's routes file unconditionally. The file is
 *    currently a no-op placeholder; the UI surfaces (`/counterparties`,
 *    `/counterparties/triage`, `/counterparties/{slug}`) land in the
 *    follow-up plan.
 *  - loads the module's view namespace `counterparties::` so module-
 *    scoped Blade views resolve symbolically.
 *  - registers the `counterparties` Blade component namespace so the
 *    8 anonymous components under `Resources/views/components/*.blade.php`
 *    are addressable as `<x-counterparties::type-chip />`,
 *    `<x-counterparties::cp-card />`, etc. across the app.
 *
 * No Livewire components are registered yet; the follow-up plan owns
 * the index / profile / triage Livewire surface.
 */
final class CounterpartiesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CounterpartyResolver::class, CounterpartyResolverService::class);
        $this->app->singleton(ResolvesCounterparties::class, ResolveCounterpartyStage::class);
    }

    public function boot(BladeCompiler $blade): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');

        $routesPath = __DIR__.'/../Routes/web.php';
        if (file_exists($routesPath)) {
            $this->loadRoutesFrom($routesPath);
        }

        $viewsPath = __DIR__.'/../Resources/views';
        if (is_dir($viewsPath)) {
            $this->loadViewsFrom($viewsPath, 'counterparties');
        }

        /*
         * Anonymous Blade components live as `.blade.php` files inside
         * Resources/views/components/. The loadViewsFrom call above
         * already makes `<x-counterparties::*>` resolve against that
         * path; the explicit componentNamespace registration here
         * mirrors the documented Laravel convention for module-provided
         * anonymous component libraries and is the single source the
         * Counterparty UI shell, profile, and triage surfaces resolve
         * against.
         */
        $blade->componentNamespace('Modules\\Counterparties\\Resources\\views\\components', 'counterparties');
    }
}
