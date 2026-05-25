<?php

declare(strict_types=1);

namespace Modules\Import\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Desktop\Public\Events\FileOpenedFromOs;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Http\Livewire\RenameCounterpartyPopover;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Internal\Listeners\HandleFileOpenedFromOs;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Pipeline\Stages\PaymentTypeClassifierStage;
use Modules\Import\Public\Actions\ApplyEnrichments;
use Modules\Import\Public\Actions\ConfirmImport;
use Modules\Import\Public\Actions\RunImport;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Services\AccountNamer;
use Psr\Log\LoggerInterface;

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
 *  - The five per-source `PaymentTypeHinter` implementations plus the
 *    universal `DescriptionKeywordFallbackHinter` are bound under the
 *    `import.payment_type_hinter` container tag so the
 *    `PaymentTypeClassifierStage` collects them via `app->tagged(...)`
 *    without ever naming a concrete FQN. Adding a future hinter is one
 *    edit (append to `PAYMENT_TYPE_HINTER_FQNS`) plus shipping the class
 *    — the classifier stage and pipeline binding stay untouched.
 *    `class_exists()` gates every singleton + tag call so a missing
 *    class does not abort container resolution.
 */
final class ImportServiceProvider extends ServiceProvider
{
    /**
     * Per-source `PaymentTypeHinter` FQNs registered under the
     * `import.payment_type_hinter` container tag. The source-specific
     * hinters lead so their higher-confidence verdicts win over the
     * fallback's; the fallback is LAST so the registry test's
     * "fallback is last" invariant holds and so ties resolve through
     * the documented registration-order rule. A missing class skips
     * its binding gracefully via the `class_exists()` guard, mirroring
     * the `ReceiptsServiceProvider` tag-loop pattern.
     */
    private const PAYMENT_TYPE_HINTER_FQNS = [
        'Modules\\Import\\Internal\\Parsers\\Asn\\AsnCamt053PaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Asn\\AsnMt940PaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Asn\\AsnCsvPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Ics\\IcsPdfPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Paypal\\PaypalCsvPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\DescriptionKeywordFallbackHinter',
    ];

    public function register(): void
    {
        $this->app->bind(RunsImports::class, RunImport::class);
        $this->app->bind(ConfirmsImports::class, ConfirmImport::class);
        $this->app->bind(NamesAccounts::class, AccountNamer::class);
        $this->app->bind(AppliesEnrichments::class, ApplyEnrichments::class);

        $this->app->singleton(ImportPipeline::class);
        $this->app->singleton(PreviewCache::class);
        $this->app->singleton(HandleFileOpenedFromOs::class);

        foreach (self::PAYMENT_TYPE_HINTER_FQNS as $fqn) {
            if (class_exists($fqn)) {
                $this->app->singleton($fqn);
                $this->app->tag([$fqn], 'import.payment_type_hinter');
            }
        }

        $this->app->singleton(
            PaymentTypeClassifierStage::class,
            static function (Container $app): PaymentTypeClassifierStage {
                /** @var iterable<PaymentTypeHinter> $tagged */
                $tagged = $app->tagged('import.payment_type_hinter');
                $hinters = [];
                foreach ($tagged as $hinter) {
                    $hinters[] = $hinter;
                }

                $logger = $app->make(LoggerInterface::class);

                return new PaymentTypeClassifierStage($hinters, $logger);
            },
        );
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
        $livewire->component('import.rename-counterparty-popover', RenameCounterpartyPopover::class);

        // SC3 routing caveat: .csv FileOpenedFromOs intents land here
        // (Import), not in Ingestion. The listener filters by extension
        // and persists the validated path into the Desktop pending-intent
        // store; the user then lands on the Desktop staging page bound
        // to the file.
        $events->listen(FileOpenedFromOs::class, [HandleFileOpenedFromOs::class, 'handle']);
    }
}
