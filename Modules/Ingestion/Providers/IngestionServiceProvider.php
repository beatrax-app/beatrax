<?php

declare(strict_types=1);

namespace Modules\Ingestion\Providers;

use Illuminate\Support\ServiceProvider;

/**
 * Loads Ingestion module migrations, routes, and views. Adapter bindings
 * arrive in Plan 04 (AsnCsvAdapter).
 */
final class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Plan 04 binds Ingestion\Public\Contracts\SourceAdapter implementations here.
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ingestion');
    }
}
