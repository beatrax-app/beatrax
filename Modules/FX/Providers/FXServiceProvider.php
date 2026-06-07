<?php

declare(strict_types=1);

namespace Modules\FX\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the FX module.
 *
 * `register()` is minimal in plan 01 — the RateProviderRegistry tagged-singleton
 * wiring and the ExchangeRateService singleton are added in plan 02 once those
 * classes exist. This plan establishes the bootable skeleton.
 *
 * `boot()` loads migrations from `Database/Migrations` and views under the `fx`
 * namespace, using the conditional is_dir() guard pattern established by
 * BudgetsServiceProvider and ReceiptsServiceProvider.
 *
 * No Livewire components in v1 — the FX module has no own pages.
 * No Routes/web.php — FX settings live on the Core SettingsPage.
 * No Routes/console.php — the daily FX refresh schedule entry lives in the
 * root routes/console.php alongside recurring.detect and forecasting.daily-sweep.
 */
final class FXServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // RateProviderRegistry + ExchangeRateService singleton bindings added in plan 02.
    }

    public function boot(): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }

        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'fx');
        }
    }
}
