<?php

declare(strict_types=1);

namespace Modules\Ingestion\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Modules\Ingestion\Internal\Adapters\Asn\AsnCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Banking\Camt053Adapter;
use Modules\Ingestion\Internal\Adapters\Banking\Mt940Adapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAdapter;
use Modules\Ingestion\Internal\Adapters\Csv\GenericCsvAmountParser;
use Modules\Ingestion\Internal\Adapters\Ics\IcsPdfAdapter;
use Modules\Ingestion\Internal\Adapters\Paypal\PaypalCsvAdapter;
use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Ingestion\Public\Services\SourceAdapterRegistry;

/**
 * @link ../../../.docs/features/ingestion/architecture.md
 */
final class IngestionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CsvPresetRegistry::class);
        $this->app->singleton(HeaderSniffer::class);

        $this->app->singleton(
            SourceAdapterRegistry::class,
            static function (Container $app): SourceAdapterRegistry {
                $adapters = [
                    SourceFormat::AsnCsv->value => $app->make(AsnCsvAdapter::class),
                    SourceFormat::Camt053->value => $app->make(Camt053Adapter::class),
                    SourceFormat::Mt940->value => $app->make(Mt940Adapter::class),
                    'ics-pdf' => $app->make(IcsPdfAdapter::class),
                    'paypal-csv' => $app->make(PaypalCsvAdapter::class),
                ];

                // One GenericCsvAdapter per bundled CSV preset (N26, Revolut,
                // ING-NL, …) — each is its own ingestion format.
                $presets = $app->make(CsvPresetRegistry::class);
                $amounts = $app->make(GenericCsvAmountParser::class);
                $sniffer = $app->make(HeaderSniffer::class);
                foreach ($presets->all() as $format => $preset) {
                    $adapters[$format] = new GenericCsvAdapter($preset, $amounts, $sniffer);
                }

                return new SourceAdapterRegistry($adapters);
            },
        );
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
    }
}
