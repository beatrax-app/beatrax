<?php

declare(strict_types=1);

namespace Modules\Import\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Desktop\Public\Events\FileOpenedFromOs;
use Modules\Import\Database\Seeders\DefaultKnownCounterpartyIbansSeeder;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Http\Livewire\RenameCounterpartyPopover;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Internal\Listeners\HandleFileOpenedFromOs;
use Modules\Import\Internal\Listeners\SeedDefaultKnownCounterpartyIbans;
use Modules\Import\Internal\Pipeline\ImportPipeline;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Pipeline\Stages\PaymentTypeClassifierStage;
use Modules\Import\Internal\Services\AliasYamlExporter;
use Modules\Import\Internal\Services\AliasYamlImporter;
use Modules\Import\Internal\Services\KnownCounterpartyIbanResolver;
use Modules\Import\Internal\Services\LongestCommonPrefix;
use Modules\Import\Public\Actions\ApplyEnrichments;
use Modules\Import\Public\Actions\ConfirmImport;
use Modules\Import\Public\Actions\CreateMerchantAlias;
use Modules\Import\Public\Actions\MergeMerchantAliases;
use Modules\Import\Public\Actions\RunImport;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\ConfirmsImports;
use Modules\Import\Public\Contracts\DetectsStartingBalance;
use Modules\Import\Public\Contracts\NamesAccounts;
use Modules\Import\Public\Contracts\PaymentTypeHinter;
use Modules\Import\Public\Contracts\ResolvesKnownCounterpartyIban;
use Modules\Import\Public\Contracts\RunsImports;
use Modules\Import\Public\Services\AccountNamer;
use Modules\Import\Public\Services\AliasMatchPreviewQuery;
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Import\Public\Services\PatternGeneralizer;

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
 *  - The four per-source `DetectsStartingBalance` implementations are
 *    bound under the `starting-balance.detector` container tag so the
 *    starting-balance aggregator (consumed by the first-import wizard
 *    step) collects them via `app->tagged(...)` using the same
 *    discovery pattern as the payment-type hinters. Adding a future
 *    detector is one edit (append to `STARTING_BALANCE_DETECTOR_FQNS`)
 *    plus shipping the class — the aggregator and any consumers stay
 *    untouched. `class_exists()` gates every singleton + tag call so a
 *    missing class does not abort container resolution.
 *  - ResolvesKnownCounterpartyIban → KnownCounterpartyIbanResolver
 *    bridges the real institution IBAN that appears on the ASN side
 *    of a cross-account hop (PayPal Luxembourg, ICS at ABN AMRO) to
 *    the user's own synthetic-IBAN account of the matching kind.
 *    The `SeedDefaultKnownCounterpartyIbans` listener subscribes to
 *    the `UserInstalled` event so a freshly installed user receives
 *    the two seeded institution-IBAN aliases (PayPal Luxembourg →
 *    paypal kind, ICS at ABN AMRO → ics_card kind) automatically.
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
        'Modules\\Import\\Internal\\Parsers\\Banking\\Camt053PaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Banking\\Mt940PaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Asn\\AsnCsvPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Ics\\IcsPdfPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Paypal\\PaypalCsvPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\DescriptionKeywordFallbackHinter',
    ];

    /**
     * Per-source `DetectsStartingBalance` FQNs registered under the
     * `starting-balance.detector` container tag. Order matches the
     * documented detector priority used by the registry test —
     * CAMT.053 first (canonical opening balance), MT940 next
     * (legacy fallback), ICS PDF (consumer-portal statements),
     * PayPal CSV last (always declines, returns an empty list).
     * A missing class skips its binding gracefully via the
     * `class_exists()` guard, mirroring the payment-type-hinter
     * tag loop.
     */
    private const STARTING_BALANCE_DETECTOR_FQNS = [
        'Modules\\Import\\Internal\\Detectors\\Camt053StartingBalanceDetector',
        'Modules\\Import\\Internal\\Detectors\\Mt940StartingBalanceDetector',
        'Modules\\Import\\Internal\\Detectors\\IcsPdfStartingBalanceDetector',
        'Modules\\Import\\Internal\\Detectors\\PaypalCsvStartingBalanceDetector',
    ];

    public function register(): void
    {
        $this->app->bind(RunsImports::class, RunImport::class);
        $this->app->bind(ConfirmsImports::class, ConfirmImport::class);
        $this->app->bind(NamesAccounts::class, AccountNamer::class);
        $this->app->bind(AppliesEnrichments::class, ApplyEnrichments::class);
        $this->app->bind(ResolvesKnownCounterpartyIban::class, KnownCounterpartyIbanResolver::class);

        $this->app->singleton(ImportPipeline::class);
        $this->app->singleton(PreviewCache::class);
        $this->app->singleton(HandleFileOpenedFromOs::class);
        $this->app->singleton(KnownCounterpartyIbanResolver::class);
        $this->app->singleton(DefaultKnownCounterpartyIbansSeeder::class);

        // Merchant-alias collaborators: resolver is stateless/read-only,
        // generalizer is pure, preview query + create-alias action each
        // wrap a single DB op — bound as singletons since none hold
        // per-request state.
        $this->app->singleton(PatternGeneralizer::class);
        $this->app->singleton(MerchantNameResolver::class);
        $this->app->singleton(AliasMatchPreviewQuery::class);
        $this->app->singleton(CreateMerchantAlias::class);

        // Consolidated-preview read query bound as a singleton — the
        // FirstImportStep resolves it once per step render to project
        // the per-source preview sections from the stashed ImportRun
        // ids. Stateless: holds only its three constructor deps.
        $this->app->singleton(BuildConsolidatedPreviewQuery::class);

        // Settings → Aliases collaborators: LongestCommonPrefix is pure,
        // the YAML exporter wraps a single read, the YAML importer wraps
        // parse+diff+apply (apply opens a transaction), and the merge
        // action is a single transactional write — all singletons.
        $this->app->singleton(LongestCommonPrefix::class);
        $this->app->singleton(AliasYamlExporter::class);
        $this->app->singleton(AliasYamlImporter::class);
        $this->app->singleton(MergeMerchantAliases::class);

        foreach (self::PAYMENT_TYPE_HINTER_FQNS as $fqn) {
            if (class_exists($fqn)) {
                $this->app->singleton($fqn);
                $this->app->tag([$fqn], 'import.payment_type_hinter');
            }
        }

        foreach (self::STARTING_BALANCE_DETECTOR_FQNS as $fqn) {
            if (class_exists($fqn)) {
                $this->app->singleton($fqn);
                $this->app->tag([$fqn], 'starting-balance.detector');
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

                return new PaymentTypeClassifierStage($hinters);
            },
        );

        $this->app->singleton(
            DetectStartingBalancesQuery::class,
            static function (Container $app): DetectStartingBalancesQuery {
                /** @var iterable<DetectsStartingBalance> $tagged */
                $tagged = $app->tagged('starting-balance.detector');
                $detectors = [];
                foreach ($tagged as $detector) {
                    $detectors[] = $detector;
                }

                return new DetectStartingBalancesQuery($detectors);
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
        $livewire->component('import.aliases-settings-page', AliasesSettingsPage::class);

        // .csv FileOpenedFromOs intents land here (Import), not in
        // Ingestion. The listener filters by extension and persists the
        // validated path into the Desktop pending-intent store; the user
        // then lands on the Desktop staging page bound to the file.
        $events->listen(FileOpenedFromOs::class, [HandleFileOpenedFromOs::class, 'handle']);

        // Freshly installed users receive the two seeded institution-IBAN
        // aliases (PayPal Luxembourg → paypal kind, ICS at ABN AMRO →
        // ics_card kind). The listener resolves the User from the event's
        // int `userId` payload and the seeder is idempotent on re-runs.
        $events->listen(UserInstalled::class, SeedDefaultKnownCounterpartyIbans::class);
    }
}
