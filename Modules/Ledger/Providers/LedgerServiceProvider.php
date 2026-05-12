<?php

declare(strict_types=1);

namespace Modules\Ledger\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Loads Ledger module migrations, routes, and views.
 */
final class LedgerServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 03 binds Ledger\Public\Contracts\RecordsTransactions here.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ledger');
    }
}
