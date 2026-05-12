<?php

declare(strict_types=1);

namespace Modules\Ingestion\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Ingestion\Public\Services\HeaderSniffer;

/**
 * Wires the Ingestion module. Registers HeaderSniffer as a stateless
 * singleton; the SourceAdapterRegistry binding lives next to each concrete
 * adapter so adding a new source format is a single-file change.
 *
 * Adapters are NOT auto-detected from file content (per ING-07) — the user
 * declares the source format up front in the upload wizard.
 */
final class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HeaderSniffer::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ingestion');
    }
}
