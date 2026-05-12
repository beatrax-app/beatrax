<?php

declare(strict_types=1);

namespace Modules\Import\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\ConfirmImport;
use Modules\Import\Public\Actions\RunImport;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Services\AccountNamer;

/**
 * Wires the Import module:
 *
 *  - RunsImports → RunImport: orchestrates preview phase.
 *  - ConfirmsImports → ConfirmImport: replays the cached canonical rows
 *    through Ledger's RecordsTransactions.
 *  - NamesAccounts → AccountNamer: persists Account rows for unknown IBANs
 *    when the user supplies a name inline in the wizard.
 *  - ImportPipeline + PreviewCache are singletons; the pipeline holds the
 *    stateless three-stage chain, the cache wraps Laravel's cache repository
 *    with the locked JSON-only DTO round-trip (T-05-11).
 */
final class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RunsImports::class, RunImport::class);
        $this->app->bind(ConfirmsImports::class, ConfirmImport::class);
        $this->app->bind(NamesAccounts::class, AccountNamer::class);

        $this->app->singleton(ImportPipeline::class);
        $this->app->singleton(PreviewCache::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'import');
    }
}
