<?php

declare(strict_types=1);

namespace Modules\Receipts\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Receipts\Internal\Http\Livewire\ReceiptConflictToast;
use Modules\Receipts\Internal\Http\Livewire\WizardEmailFileStep;
use Modules\Receipts\Internal\Listeners\DispatchChainHintsFromReceipt;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Services\FileImportQuery;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

/**
 * Wires the Receipts module.
 *
 * Wave 0 registers the pipeline-support singletons (`EmlMimeReader`,
 * `MboxIterator`, `FileDropEmlBlobStore`) and the `MatcherRegistry`
 * resolver, which collects every binding tagged `receipts.matcher`
 * and hands them to the registry sorted by `priority()` descending.
 *
 * The per-sender matcher classes (`PaypalReceiptMatcher`,
 * `IcsReceiptMatcher`, `GooglePlayReceiptMatcher`) land in Wave 1 +
 * Wave 2; each is registered + tagged via a guarded `class_exists()`
 * branch so the empty Wave 0 skeleton boots cleanly before any
 * matcher class ships.
 *
 * `boot()` conditionally loads migrations, routes, and views — the
 * module owns its own migrations from Wave 0 (file_imports +
 * matcher_key on inbox_messages) but routes / views land alongside
 * the wizard step in Wave 1.
 *
 * No `JobFailed` listener registration here. The matcher consumer
 * (`ProcessFetchedInboxMessagesJob`, Wave 1) runs synchronously in
 * the simpler default; if a later wave promotes it to a queued job
 * the listener-shape used in Chains / EmailScan service providers
 * is the precedent to copy.
 */
final class ReceiptsServiceProvider extends ServiceProvider
{
    /**
     * Per-sender matcher FQNs registered under the `receipts.matcher`
     * container tag. Each entry is bound + tagged only when the
     * implementing class exists on disk — the Wave 0 skeleton boots
     * with an empty tagged collection; Wave 1 + Wave 2 land the
     * matcher classes and the registry's tagged() lookup picks them
     * up automatically without a provider edit.
     */
    private const MATCHER_FQNS = [
        'Modules\\Receipts\\Internal\\Matchers\\PaypalReceiptMatcher',
        'Modules\\Receipts\\Internal\\Matchers\\IcsReceiptMatcher',
        'Modules\\Receipts\\Internal\\Matchers\\GooglePlayReceiptMatcher',
    ];

    /**
     * Pipeline-support FQNs registered as stateless singletons. Each
     * binding is gated on `class_exists()` so the Wave 0 skeleton
     * (before the Internal/Pipeline/* classes land in the next plan)
     * boots without a class-not-found resolution error. Once the
     * classes ship the singleton binding activates automatically; no
     * provider edit is required at that point.
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

        // Defer the matcher FQNs so the Wave 0 skeleton boots before
        // the matcher classes ship. Once Wave 1 + Wave 2 land each
        // matcher class, the binding becomes resolvable and the tagged
        // collection populates automatically.
        foreach (self::MATCHER_FQNS as $fqn) {
            if (class_exists($fqn)) {
                $this->app->singleton($fqn);
                $this->app->tag([$fqn], 'receipts.matcher');
            }
        }

        // Resolve the tagged matcher collection and hand the
        // priority-sorted list to the registry. The tagged() lookup
        // returns an iterable; the closure materialises it once at
        // resolve-time and sorts by priority() descending so the
        // dispatch loop walks the most-specific matcher first.
        $this->app->singleton(RecordReceipt::class);
        $this->app->singleton(FileImportQuery::class);
        // Wave 2 — bridges canonical-row INSERT into ChainHintDetected
        // so the Chains module's listener can write candidate
        // chain_links rows scoped by the just-inserted transaction id.
        $this->app->singleton(DispatchChainHintsFromReceipt::class);
        // Wave 3 — first-conflict toast support: action + read query.
        // The action is singleton-bound; its __invoke is stateless and
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

        // Wave 2 — subscribe the chain-hint dispatcher to the
        // canonical-row INSERT event so receipts with extracted chain
        // hints surface as `ChainHintDetected` events for the Chains
        // module to consume. The subscription lives in boot() because
        // it depends on the injected Dispatcher; the class itself is
        // registered as a singleton in register() so the listener
        // shares one Dispatcher instance per request.
        $events->listen(TransactionImported::class, [DispatchChainHintsFromReceipt::class, 'handle']);
    }
}
