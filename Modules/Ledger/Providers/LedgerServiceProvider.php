<?php

declare(strict_types=1);

namespace Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Wires the Ledger module: loads migrations + routes + views, and registers
 * Public contract bindings (FingerprintComposer + PeriodQuery singletons,
 * RecordsTransactions + UpdatesTransactionCategory bindings) once the
 * implementing classes ship.
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Public contract bindings + service singletons are wired in
        // subsequent migrations of this provider as their implementations land.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ledger');
    }
}
