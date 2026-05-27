<?php

declare(strict_types=1);

namespace Modules\Transfers\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Ledger\Public\Actions\RecordTransactions;
use Modules\Transfers\Internal\Listeners\PairTransferCandidates;
use Modules\Transfers\Internal\Services\TransferPairer;
use Modules\Transfers\Public\Contracts\PairsTransferLegs;
use Modules\Transfers\Public\Services\PairLookup;

/**
 * Wires the Transfers module:
 *
 *  - binds the {@see PairsTransferLegs} contract to its concrete
 *    {@see TransferPairer} so both the per-row listener path and the
 *    bulk orphan-sweep path used by the chain-resolution job resolve
 *    the same matcher.
 *
 *  - subscribes the deterministic {@see PairTransferCandidates}
 *    listener to the cross-module {@see TransactionImported} event
 *    fired by {@see RecordTransactions}.
 *
 *  - binds the read-side {@see PairLookup} for downstream chain-
 *    resolution consumers (Chains module).
 *
 * The provider does NOT call loadMigrationsFrom() / loadRoutesFrom() /
 * loadViewsFrom() — there are no migrations (the pair_transaction_id
 * column lives on `transactions` and is owned by the Ledger module),
 * no routes, and no views.
 */
final class TransfersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Public read API over `transactions.pair_transaction_id`,
        // consumed by the Chains module's resolvers. Stateless query
        // class — singleton-bound so downstream modules can resolve it
        // without holding their own DatabaseManager reference.
        $this->app->singleton(PairLookup::class);

        // Concrete matcher behind the public contract. Singleton-bound
        // so the per-row listener path and the bulk orphan-sweep path
        // share a single instance — every method on the service is
        // pure-deterministic against its inputs, no per-instance state.
        $this->app->singleton(TransferPairer::class);
        $this->app->bind(PairsTransferLegs::class, TransferPairer::class);

        // The internal listener stays auto-resolved via constructor DI
        // when the dispatcher invokes it.
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(TransactionImported::class, PairTransferCandidates::class);
    }
}
