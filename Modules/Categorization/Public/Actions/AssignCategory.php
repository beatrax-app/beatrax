<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Actions;

use Illuminate\Contracts\Events\Dispatcher;
use Modules\Categorization\Public\Contracts\AssignsCategory;
use Modules\Categorization\Public\Events\TransactionCategorized;
use Modules\Core\Models\User;
use Modules\Ledger\Public\Contracts\UpdatesTransactionCategory;

/**
 * Default implementation of the Categorization Public `AssignsCategory`
 * contract. Routes the write through Ledger's `UpdatesTransactionCategory`
 * action (D-04 — Ledger stays the only mutator of `transactions`), and
 * fires the `TransactionCategorized` event after a successful write so
 * later phases (Phase 7 MerchantMemory) can react without coupling.
 */
final class AssignCategory implements AssignsCategory
{
    public function __construct(
        private readonly UpdatesTransactionCategory $updater,
        private readonly Dispatcher $events,
    ) {}

    public function __invoke(int $transactionId, ?int $categoryId, User $user): int
    {
        $affected = ($this->updater)($transactionId, $categoryId, $user);

        if ($affected > 0) {
            $this->events->dispatch(new TransactionCategorized(
                transactionId: $transactionId,
                categoryId: $categoryId,
                userId: $user->id,
            ));
        }

        return $affected;
    }
}
