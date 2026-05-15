<?php

declare(strict_types=1);

namespace Modules\Transfers\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Modules\Import\Public\Events\TransactionImported;
use Modules\Transfers\Internal\Listeners\PairTransferCandidates;

/**
 * Wires the Transfers module:
 *
 *  - subscribes the deterministic PairTransferCandidates listener to
 *    the cross-module TransactionImported event fired by
 *    `Modules\Ledger\Public\Actions\RecordTransactions`.
 *
 * The module ships no `Public/` API surface today — the listener is
 * the entire module. The boundary stays clean until a future consumer
 * needs a public service (e.g. a `PairLookup` query for downstream
 * chain-resolution).
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
        // No bindings — the listener is auto-resolved via constructor
        // DI when the dispatcher invokes it.
    }

    public function boot(Dispatcher $events): void
    {
        $events->listen(TransactionImported::class, PairTransferCandidates::class);
    }
}
