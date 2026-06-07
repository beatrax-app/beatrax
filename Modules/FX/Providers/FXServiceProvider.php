<?php

declare(strict_types=1);

namespace Modules\FX\Providers;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Modules\FX\Internal\Providers\BundledSnapshotProvider;
use Modules\FX\Internal\Providers\EcbRateProvider;
use Modules\FX\Internal\Providers\FrankfurterRateProvider;
use Modules\FX\Internal\RateProviderRegistry;
use Modules\FX\Public\Contracts\RateProvider;
use Modules\FX\Public\Services\ExchangeRateService;

/**
 * Wires the FX module.
 *
 * `register()` tags and registers the three rate providers as singletons,
 * binds `RateProviderRegistry` (sorted by priority() DESC), and binds
 * `ExchangeRateService` as a singleton — mirroring the ReceiptsServiceProvider
 * tagged-singleton pattern exactly (01-PATTERNS.md lines 133–189).
 *
 * `boot()` loads migrations under `Database/Migrations` and views under
 * `Resources/views` using the conditional `is_dir()` guard established by
 * BudgetsServiceProvider and ReceiptsServiceProvider.
 *
 * No Livewire components in v1 — FX has no own pages; settings live on
 * the Core SettingsPage. No Routes/web.php or module-local Routes/console.php;
 * the daily refresh entry lives in the root routes/console.php.
 */
final class FXServiceProvider extends ServiceProvider
{
    /** @var list<class-string<RateProvider>> */
    private const array PROVIDER_FQNS = [
        EcbRateProvider::class,
        FrankfurterRateProvider::class,
        BundledSnapshotProvider::class,
    ];

    public function register(): void
    {
        foreach (self::PROVIDER_FQNS as $fqn) {
            if (class_exists($fqn)) {
                $this->app->singleton($fqn);
                $this->app->tag([$fqn], 'fx.rate_provider');
            }
        }

        $this->app->singleton(
            RateProviderRegistry::class,
            static function (Application $app): RateProviderRegistry {
                /** @var iterable<int|string, mixed> $tagged */
                $tagged = $app->tagged('fx.rate_provider');

                /** @var list<RateProvider> $providers */
                $providers = [];

                foreach ($tagged as $p) {
                    if ($p instanceof RateProvider) {
                        $providers[] = $p;
                    }
                }

                usort(
                    $providers,
                    static fn (RateProvider $a, RateProvider $b): int => $b->priority() <=> $a->priority(),
                );

                $cache = $app->make(Repository::class);

                return new RateProviderRegistry($providers, $cache);
            },
        );

        $this->app->singleton(ExchangeRateService::class);
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
