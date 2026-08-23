<?php

declare(strict_types=1);

namespace Modules\Ingestion\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Public\Support\LoadsModuleResources;
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

final class IngestionServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

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
                    SourceFormat::IcsPdf->value => $app->make(IcsPdfAdapter::class),
                    SourceFormat::PaypalCsv->value => $app->make(PaypalCsvAdapter::class),
                ];

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
        $this->loadModuleResources('ingestion');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
    }
}
