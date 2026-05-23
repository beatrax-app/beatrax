<?php

declare(strict_types=1);

namespace Modules\Import\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Desktop\Public\Events\FileOpenedFromOs;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Internal\Listeners\HandleFileOpenedFromOs;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Public\Actions\ApplyEnrichments;
use Modules\Import\Public\Actions\ConfirmImport;
use Modules\Import\Public\Actions\RunImport;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Services\AccountNamer;

/**
 * Wires the Import module:
 *
 *  - RunsImports → RunImport: orchestrates preview phase.
 *  - ConfirmsImports → ConfirmImport: replays the cached canonical rows
 *    through Ledger's RecordsTransactions and applies pending enrichments
 *    inside the same DB transaction.
 *  - NamesAccounts → AccountNamer: persists Account rows for unknown IBANs
 *    when the user supplies a name inline in the wizard.
 *  - AppliesEnrichments → ApplyEnrichments: writes stronger source_ref
 *    values onto existing transactions and appends provenance entries to
 *    `enriched_from` for cross-format re-imports.
 *  - ImportPipeline + PreviewCache are singletons; the pipeline holds the
 *    stateless three-stage chain, the cache wraps Laravel's cache repository
 *    with the JSON-only DTO round-trip used by PreviewCache.
 */
final class ImportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(RunsImports::class, RunImport::class);
        $this->app->bind(ConfirmsImports::class, ConfirmImport::class);
        $this->app->bind(NamesAccounts::class, AccountNamer::class);
        $this->app->bind(AppliesEnrichments::class, ApplyEnrichments::class);

        $this->app->singleton(ImportPipeline::class);
        $this->app->singleton(PreviewCache::class);
        $this->app->singleton(HandleFileOpenedFromOs::class);
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'import');

        $livewire->component('import.upload-wizard', UploadWizard::class);
        $livewire->component('import.preview-wizard', PreviewWizard::class);
        $livewire->component('import.import-results', ImportResults::class);

        // SC3 routing caveat: .csv FileOpenedFromOs intents land here
        // (Import), not in Ingestion. The listener filters by extension
        // and persists the validated path into the Desktop pending-intent
        // store; the user then lands on the Desktop staging page bound
        // to the file.
        $events->listen(FileOpenedFromOs::class, [HandleFileOpenedFromOs::class, 'handle']);
    }
}
