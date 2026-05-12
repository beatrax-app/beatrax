<?php

declare(strict_types=1);

namespace Modules\Categorization\Internal\Examples;

// GOOD: cross-module access goes through Public/ or Models/.
use Illuminate\Contracts\Database\Eloquent\Builder;
use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\Contracts\RecordsTransactions;

final class GoodBoundaryFixture
{
    public function __construct(private RecordsTransactions $writer) {}

    /**
     * @return Builder<Transaction>
     */
    public function query(): Builder
    {
        return Transaction::query();
    }
}
