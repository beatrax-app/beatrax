<?php

declare(strict_types=1);

namespace Modules\DevMode\Providers;

use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\DevMode\Internal\Audit\NullAuditWriter;
use Modules\DevMode\Internal\Http\Livewire\DevOverviewPage;
use Modules\DevMode\Internal\Http\Middleware\EnsureDeveloperMode;
use Modules\DevMode\Internal\Registries\NullAppActionRegistry;
use Modules\DevMode\Internal\Registries\NullDevCommandRegistry;
use Modules\DevMode\Internal\Registries\NullNavigationRegistry;
use Modules\DevMode\Public\Contracts\AppActionRegistry;
use Modules\DevMode\Public\Contracts\AuditWriter;
use Modules\DevMode\Public\Contracts\DevCommandRegistry;
use Modules\DevMode\Public\Contracts\NavigationRegistry;

/**
 * Wires the DevMode module.
 *
 * `register()` binds the four Public contracts to their default Null*
 * concretes so consumer code can resolve the contracts from day one:
 *
 *   - DevCommandRegistry → NullDevCommandRegistry (replaced in 16-04
 *     by DevCommandRegistryImpl which hard-codes the CONTEXT D-12 +
 *     D-13 allow-lists)
 *   - NavigationRegistry → NullNavigationRegistry (replaced in 16-08
 *     by NavigationRegistryImpl)
 *   - AppActionRegistry → NullAppActionRegistry (replaced in 16-08
 *     by AppActionRegistryImpl)
 *   - AuditWriter → NullAuditWriter (replaced in 16-04 by
 *     SpatieAuditWriter, which routes through
 *     spatie/laravel-activitylog into the renamed `dev_mode_audit`
 *     table per CONTEXT D-23 + D-24)
 *
 * Later plans REPLACE the binding from their own ServiceProviders;
 * the Null* shape is the default so no consumer needs an
 * `app()->bound(...)` null-check.
 *
 * `boot()` registers the `ensureDeveloperMode` middleware alias (the
 * sole gate on `/dev/*`), loads the module's migrations + routes +
 * views, and registers the dev-shell-mounted Livewire components.
 *
 * Per CLAUDE.md DI-only carve-out: no facade calls anywhere in this
 * provider; the Router collaborator is method-injected via the
 * framework's container.
 */
final class DevModeServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DevCommandRegistry::class, NullDevCommandRegistry::class);
        $this->app->singleton(NavigationRegistry::class, NullNavigationRegistry::class);
        $this->app->singleton(AppActionRegistry::class, NullAppActionRegistry::class);
        $this->app->singleton(AuditWriter::class, NullAuditWriter::class);
    }

    public function boot(Router $router, LivewireManager $livewire): void
    {
        $router->aliasMiddleware('ensureDeveloperMode', EnsureDeveloperMode::class);

        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dev');
        }

        $livewire->component('dev.overview-page', DevOverviewPage::class);
    }
}
