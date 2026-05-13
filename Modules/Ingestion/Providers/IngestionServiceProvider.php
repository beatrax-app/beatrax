<?php

declare(strict_types=1);

namespace Modules\Ingestion\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Modules\Ingestion\Internal\Adapters\Asn\AsnCamt053Adapter;
use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Asn\AsnMt940Adapter;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

/**
 * Wires the Ingestion module.
 *
 * - HeaderSniffer is a stateless singleton injected into the upload wizard
 *   for pre-parse validation.
 * - SourceAdapterRegistry maps stable format identifiers to their adapter
 *   implementation. Adapters are NOT auto-detected from file content — the
 *   user declares the source format up front. New source formats are added
 *   to the registry map alongside their adapter class.
 */
final class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(HeaderSniffer::class);

        $this->app->singleton(
            SourceAdapterRegistry::class,
            static fn (Container $app): SourceAdapterRegistry => new SourceAdapterRegistry([
                'asn-csv' => $app->make(AsnCsvAdapter::class),
                'asn-camt053' => $app->make(AsnCamt053Adapter::class),
                'asn-mt940' => $app->make(AsnMt940Adapter::class),
            ]),
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'ingestion');
    }
}
