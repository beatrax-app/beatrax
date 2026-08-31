<?php

declare(strict_types=1);

namespace Modules\Receipts\Providers;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Livewire\LivewireManager;
use Modules\Core\Public\Support\LoadsModuleResources;
use Modules\Core\Public\Support\RegistersScheduledCommands;
use Modules\Desktop\Public\Events\FileOpenedFromOs;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Receipts\Internal\Console\ScanInboxDropFolderCommand;
use Modules\Receipts\Internal\Http\Livewire\ReceiptConflictToast;
use Modules\Receipts\Internal\Listeners\DispatchChainHintsFromReceipt;
use Modules\Receipts\Internal\Listeners\HandleFileOpenedFromOs;
use Modules\Receipts\Internal\MatcherRegistry;
use Modules\Receipts\Internal\Matchers\GooglePlayReceiptMatcher;
use Modules\Receipts\Internal\Matchers\IcsReceiptMatcher;
use Modules\Receipts\Internal\Matchers\PaypalReceiptMatcher;
use Modules\Receipts\Internal\Matchers\ReceiptBodyText;
use Modules\Receipts\Internal\ReceiptLedgerBridge;
use Modules\Receipts\Public\Actions\ApplyReceiptConflictResolution;
use Modules\Receipts\Public\Actions\RecordReceipt;
use Modules\Receipts\Public\Contracts\SenderMatcher;
use Modules\Receipts\Public\Pipeline\EmlMimeReader;
use Modules\Receipts\Public\Pipeline\FileDropEmlBlobStore;
use Modules\Receipts\Public\Pipeline\MboxIterator;
use Modules\Receipts\Public\Pipeline\ReceiptSourceAdapter;
use Modules\Receipts\Public\Services\ReceiptConflictQuery;

final class ReceiptsServiceProvider extends ServiceProvider
{
    use LoadsModuleResources;
    use RegistersScheduledCommands;

    /** @var list<class-string<SenderMatcher>> */
    private const array MATCHER_FQNS = [
        PaypalReceiptMatcher::class,
        IcsReceiptMatcher::class,
        GooglePlayReceiptMatcher::class,
    ];

    /** @var list<class-string> */
    private const array PIPELINE_FQNS = [
        EmlMimeReader::class,
        MboxIterator::class,
        FileDropEmlBlobStore::class,
        ReceiptSourceAdapter::class,
    ];

    public function register(): void
    {
        $this->app->singleton(ReceiptBodyText::class);
        $this->app->singleton(ReceiptLedgerBridge::class);

        foreach (self::PIPELINE_FQNS as $fqn) {
            $this->app->singleton($fqn);
        }

        foreach (self::MATCHER_FQNS as $fqn) {
            $this->app->singleton($fqn);
            $this->app->tag([$fqn], 'receipts.matcher');
        }

        $this->app->singleton(RecordReceipt::class);
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
        $this->loadModuleResources('receipts');
        if (is_file(__DIR__.'/../Routes/console.php')) {
            $this->loadRoutesFrom(__DIR__.'/../Routes/console.php');
        }

        $this->registerScheduledCommands([ScanInboxDropFolderCommand::class]);

        $livewire->component('receipts.receipt-conflict-toast', ReceiptConflictToast::class);

        $events->listen(TransactionImported::class, [DispatchChainHintsFromReceipt::class, 'handle']);
        $events->listen(FileOpenedFromOs::class, [HandleFileOpenedFromOs::class, 'handle']);
    }
}
