<?php

declare(strict_types=1);

namespace Modules\Transfers\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Transfers\Internal\Listeners\PairTransferCandidates;
use Modules\Transfers\Internal\Services\TransferPairer;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use Modules\Transfers\Public\Services\PairLookup;

/**
 * @link ../../../.docs/features/transfers/architecture.md
 */
final class TransfersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PairLookup::class);

        $this->app->singleton(TransferPairer::class);
        $this->app->bind(PairsTransferLegs::class, TransferPairer::class);
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(TransactionImported::class, PairTransferCandidates::class);
    }
}
