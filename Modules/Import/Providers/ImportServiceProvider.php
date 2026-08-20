<?php

declare(strict_types=1);

namespace Modules\Import\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Events\UserInstalled;
use Modules\Core\Public\Support\LoadsModuleResources;
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
use Modules\Import\Internal\Sync\NullImportSyncCapture;
use Modules\Import\Public\Actions\ApplyEnrichments;
use Modules\Import\Public\Actions\ConfirmImport;
use Modules\Import\Public\Actions\CreateMerchantAlias;
use Modules\Import\Public\Actions\MergeMerchantAliases;
use Modules\Import\Public\Actions\RunImport;
use Modules\Import\Public\Contracts\AppliesEnrichments;
use Modules\Import\Public\Contracts\CapturesImportForSync;
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
 * @link ../../../.docs/features/import/architecture.md#module-boundary
 */
final class ImportServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    // Source-specific hinters lead so their higher-confidence verdicts
    // win; the fallback is LAST so the registry test's "fallback is
    // last" invariant holds. A missing class skips its binding via
    // class_exists(), mirroring ReceiptsServiceProvider's tag loop.
    private const PAYMENT_TYPE_HINTER_FQNS = [
        'Modules\\Import\\Internal\\Parsers\\Banking\\Camt053PaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Banking\\Mt940PaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Asn\\AsnCsvPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Ics\\IcsPdfPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\Paypal\\PaypalCsvPaymentTypeHinter',
        'Modules\\Import\\Internal\\Parsers\\DescriptionKeywordFallbackHinter',
    ];

    // Order matches the documented detector priority: CAMT.053 first
    // (canonical), MT940 next (legacy fallback), ICS PDF, PayPal CSV
    // last (always declines). Same class_exists() guard as above.
    private const STARTING_BALANCE_DETECTOR_FQNS = [
        'Modules\\Import\\Internal\\Detectors\\Camt053StartingBalanceDetector',
        'Modules\\Import\\Internal\\Detectors\\Mt940StartingBalanceDetector',
        'Modules\\Import\\Internal\\Detectors\\IcsPdfStartingBalanceDetector',
        'Modules\\Import\\Internal\\Detectors\\PaypalCsvStartingBalanceDetector',
    ];

    public function register(): void
    {
        // Overridden by the Sync module when it is loaded; this default keeps
        // the import path resolvable on a build without sync.
        $this->app->bindIf(CapturesImportForSync::class, NullImportSyncCapture::class);

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
        $this->loadModuleResources('import');
        $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');

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
