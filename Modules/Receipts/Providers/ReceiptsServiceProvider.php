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
 * Wires the Receipts module.
 *
 * `register()` binds the pipeline-support singletons (EmlMimeReader,
 * MboxIterator, FileDropEmlBlobStore, ReceiptSourceAdapter), the
 * per-sender matcher classes (PaypalReceiptMatcher, IcsReceiptMatcher,
 * GooglePlayReceiptMatcher) tagged under `receipts.matcher`, and the
 * MatcherRegistry resolver that collects every tagged matcher and
 * hands them to the registry sorted by `priority()` descending. The
 * Public action + query singletons (RecordReceipt, FileImportQuery,
 * ApplyReceiptConflictResolution, ReceiptConflictQuery) and the
 * receipt-chain hint dispatcher (DispatchChainHintsFromReceipt) are
 * also bound here.
 *
 * Every binding is gated on `class_exists()` so a missing class does
 * not abort container resolution — adding a new matcher requires
 * appending the FQN to `MATCHER_FQNS` and shipping the class; no
 * provider edit is required at that point.
 *
 * `boot()` loads migrations from `Database/Migrations`, web routes
 * from `Routes/web.php`, console routes from `Routes/console.php`,
 * and views under the `receipts` namespace. It also registers the
 * two Livewire components (`receipts.wizard-email-file-step`,
 * `receipts.receipt-conflict-toast`) and subscribes
 * `DispatchChainHintsFromReceipt` to `TransactionImported` so receipts
 * with extracted chain hints surface as `ChainHintDetected` events
 * for the Chains module to consume.
 */
final class ReceiptsServiceProvider extends ServiceProvider
{
    /**
     * Per-sender matcher FQNs registered under the `receipts.matcher`
     * container tag. Each entry is bound + tagged only when the
     * implementing class exists on disk so a missing class does not
     * abort container resolution.
     */
    private const MATCHER_FQNS = [
        'Modules\\Receipts\\Internal\\Matchers\\PaypalReceiptMatcher',
        'Modules\\Receipts\\Internal\\Matchers\\IcsReceiptMatcher',
        'Modules\\Receipts\\Internal\\Matchers\\GooglePlayReceiptMatcher',
    ];

    /**
     * Pipeline-support FQNs registered as stateless singletons. Each
     * binding is gated on `class_exists()` so a missing class does
     * not abort container resolution.
     */
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
        // First-conflict toast support: action + read query. The
        // action is singleton-bound; its __invoke is stateless and
        // reads the user policy inline per call (no cross-user state).
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

        $livewire->component('receipts.wizard-email-file-step', WizardEmailFileStep::class);
        $livewire->component('receipts.receipt-conflict-toast', ReceiptConflictToast::class);

        // Subscribe the chain-hint dispatcher to the canonical-row
        // INSERT event so receipts with extracted chain hints surface
        // as ChainHintDetected events for the Chains module to
        // consume. The subscription lives in boot() because it
        // depends on the injected Dispatcher; the class itself is
        // registered as a singleton in register() so the listener
        // shares one Dispatcher instance per request.
        $events->listen(TransactionImported::class, [DispatchChainHintsFromReceipt::class, 'handle']);

        // .eml FileOpenedFromOs intents land here. The listener filters
        // by extension and persists the validated path into the Desktop
        // pending-intent store; the user then lands on the Desktop
        // staging page bound to the file, where "Start import" routes
        // into the email-file step of the upload wizard.
        $events->listen(FileOpenedFromOs::class, [HandleFileOpenedFromOs::class, 'handle']);
    }
}
