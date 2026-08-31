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
use Modules\Import\Internal\Detectors\Camt053StartingBalanceDetector;
use Modules\Import\Internal\Detectors\IcsPdfStartingBalanceDetector;
use Modules\Import\Internal\Detectors\Mt940StartingBalanceDetector;
use Modules\Import\Internal\Detectors\PaypalCsvStartingBalanceDetector;
use Modules\Import\Internal\Http\Livewire\AliasesSettingsPage;
use Modules\Import\Internal\Http\Livewire\ImportResults;
use Modules\Import\Internal\Http\Livewire\PreviewWizard;
use Modules\Import\Internal\Http\Livewire\RenameCounterpartyPopover;
use Modules\Import\Internal\Http\Livewire\UploadWizard;
use Modules\Import\Internal\Listeners\HandleFileOpenedFromOs;
use Modules\Import\Internal\Listeners\SeedDefaultKnownCounterpartyIbans;
use Modules\Import\Internal\Parsers\Banking\Camt053PaymentTypeHinter;
use Modules\Import\Internal\Parsers\Banking\Mt940PaymentTypeHinter;
use Modules\Import\Internal\Parsers\Csv\PositionalCsvPaymentTypeHinter;
use Modules\Import\Internal\Parsers\DescriptionKeywordFallbackHinter;
use Modules\Import\Internal\Parsers\Ics\IcsPdfPaymentTypeHinter;
use Modules\Import\Internal\Parsers\Paypal\PaypalCsvPaymentTypeHinter;
use Modules\Import\Internal\Pipeline\PreviewCache;
use Modules\Import\Internal\Pipeline\Stages\PaymentTypeClassifierStage;
use Modules\Import\Internal\Services\AliasYamlExporter;
use Modules\Import\Internal\Services\KnownCounterpartyIbanResolver;
use Modules\Import\Internal\Services\LongestCommonPrefix;
use Modules\Import\Internal\Services\OwnAccountPrompt;
use Modules\Import\Internal\Sync\NullImportSyncCapture;
use Modules\Import\Public\Actions\ApplyEnrichments;
use Modules\Import\Public\Actions\ConfirmImport;
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
use Modules\Import\Public\Services\BuildConsolidatedPreviewQuery;
use Modules\Import\Public\Services\DetectStartingBalancesQuery;
use Modules\Import\Public\Services\MerchantNameResolver;
use Modules\Import\Public\Services\PatternGeneralizer;

final class ImportServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;

    // Source-specific hinters lead so their higher-confidence verdicts win;
    // the fallback must stay last.
    /** @var list<class-string> */
    private const array PAYMENT_TYPE_HINTER_FQNS = [
        Camt053PaymentTypeHinter::class,
        Mt940PaymentTypeHinter::class,
        PositionalCsvPaymentTypeHinter::class,
        IcsPdfPaymentTypeHinter::class,
        PaypalCsvPaymentTypeHinter::class,
        DescriptionKeywordFallbackHinter::class,
    ];

    // Detector priority: canonical CAMT.053 first, legacy MT940 next, then
    // ICS PDF, then PayPal CSV, which always declines.
    /** @var list<class-string> */
    private const array STARTING_BALANCE_DETECTOR_FQNS = [
        Camt053StartingBalanceDetector::class,
        Mt940StartingBalanceDetector::class,
        IcsPdfStartingBalanceDetector::class,
        PaypalCsvStartingBalanceDetector::class,
    ];

    public function register(): void
    {
        // Sync overrides this when loaded; the default keeps the import path
        // resolvable on a build without it.
        $this->app->bindIf(CapturesImportForSync::class, NullImportSyncCapture::class);

        $this->app->bind(RunsImports::class, RunImport::class);
        $this->app->bind(ConfirmsImports::class, ConfirmImport::class);
        $this->app->bind(NamesAccounts::class, AccountNamer::class);
        $this->app->bind(AppliesEnrichments::class, ApplyEnrichments::class);
        $this->app->bind(ResolvesKnownCounterpartyIban::class, KnownCounterpartyIbanResolver::class);

        $this->app->singleton(PreviewCache::class);
        $this->app->singleton(OwnAccountPrompt::class);
        $this->app->singleton(HandleFileOpenedFromOs::class);
        $this->app->singleton(KnownCounterpartyIbanResolver::class);
        $this->app->singleton(DefaultKnownCounterpartyIbansSeeder::class);

        $this->app->singleton(PatternGeneralizer::class);
        $this->app->singleton(MerchantNameResolver::class);

        $this->app->singleton(BuildConsolidatedPreviewQuery::class);

        $this->app->singleton(LongestCommonPrefix::class);
        $this->app->singleton(AliasYamlExporter::class);

        foreach (self::PAYMENT_TYPE_HINTER_FQNS as $fqn) {
            $this->app->singleton($fqn);
            $this->app->tag([$fqn], 'import.payment_type_hinter');
        }

        foreach (self::STARTING_BALANCE_DETECTOR_FQNS as $fqn) {
            $this->app->singleton($fqn);
            $this->app->tag([$fqn], 'starting-balance.detector');
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

        $events->listen(FileOpenedFromOs::class, [HandleFileOpenedFromOs::class, 'handle']);

        $events->listen(UserInstalled::class, SeedDefaultKnownCounterpartyIbans::class);
    }
}
