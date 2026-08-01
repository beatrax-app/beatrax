<?php

declare(strict_types=1);

namespace Modules\Receipts\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Desktop\Public\Events\FileOpenedFromOs;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Receipts\Internal\Http\Livewire\ReceiptConflictToast;
use Modules\Receipts\Internal\Http\Livewire\WizardEmailFileStep;
use Modules\Receipts\Internal\Listeners\DispatchChainHintsFromReceipt;
use Modules\Receipts\Internal\Listeners\HandleFileOpenedFromOs;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Services\FileImportQuery;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

/**
 * @link ../../../.docs/features/receipts/architecture.md
 */
final class ReceiptsServiceProvider extends ServiceProvider
{
    // Each entry is bound + tagged under receipts.matcher only when the
    // implementing class exists on disk, so a missing class never
    // aborts container resolution.
    private const MATCHER_FQNS = [
        'Modules\\Receipts\\Internal\\Matchers\\PaypalReceiptMatcher',
        'Modules\\Receipts\\Internal\\Matchers\\IcsReceiptMatcher',
        'Modules\\Receipts\\Internal\\Matchers\\GooglePlayReceiptMatcher',
    ];

    // Stateless singletons, each gated on class_exists() for the same
    // reason as MATCHER_FQNS above.
    private const PIPELINE_FQNS = [
        'Modules\\Receipts\\Public\\Pipeline\\EmlMimeReader',
        'Modules\\Receipts\\Public\\Pipeline\\MboxIterator',
        'Modules\\Receipts\\Public\\Pipeline\\FileDropEmlBlobStore',
        'Modules\\Receipts\\Public\\Pipeline\\ReceiptSourceAdapter',
    ];

    public function register(): void
    {
        foreach (self::PIPELINE_FQNS as $fqn) {
            if (class_exists($fqn)) {
                $this->app->singleton($fqn);
            }
        }

        foreach (self::MATCHER_FQNS as $fqn) {
            if (class_exists($fqn)) {
                $this->app->singleton($fqn);
                $this->app->tag([$fqn], 'receipts.matcher');
            }
        }

        $this->app->singleton(RecordReceipt::class);
        $this->app->singleton(FileImportQuery::class);
        $this->app->singleton(DispatchChainHintsFromReceipt::class);
        $this->app->singleton(HandleFileOpenedFromOs::class);
        $this->app->singleton(ApplyReceiptConflictResolution::class);
        $this->app->singleton(ReceiptConflictQuery::class);

        $this->app->singleton(
            MatcherRegistry::class,
            static function (Container $app): MatcherRegistry {
                /** @var iterable<SenderMatcher> $tagged */
                $tagged = $app->tagged('receipts.matcher');
                $matchers = [];
                foreach ($tagged as $matcher) {
                    $matchers[] = $matcher;
                }
                usort(
                    $matchers,
                    static fn (SenderMatcher $a, SenderMatcher $b): int => $b->priority() <=> $a->priority(),
                );

                return new MatcherRegistry($matchers);
            },
        );
    }

    public function boot(LivewireManager $livewire, Dispatcher $events): void
    {
        if (is_dir(__DIR__.'/../Database/Migrations')) {
            $this->loadMigrationsFrom(__DIR__.'/../Database/Migrations');
        }
        if (is_file(__DIR__.'/../Routes/web.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/web.php');
        }
        if (is_file(__DIR__.'/../Routes/console.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        }
        if (is_dir(__DIR__.'/../Resources/views')) {
            $this->loadViewsFrom(__DIR__.'/../Resources/views', 'receipts');
        }
        $this->loadTranslationsFrom(__DIR__.'/../Resources/lang', 'receipts');

        $livewire->component('receipts.wizard-email-file-step', WizardEmailFileStep::class);
        $livewire->component('receipts.receipt-conflict-toast', ReceiptConflictToast::class);

        $events->listen(TransactionImported::class, [DispatchChainHintsFromReceipt::class, 'handle']);
        $events->listen(FileOpenedFromOs::class, [HandleFileOpenedFromOs::class, 'handle']);
    }
}
