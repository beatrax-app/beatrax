<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Examples;

use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;

final class GoodFingerprintTupleFixture
{
    public function rewriteAmount(int $id, int $minor, string $currency, string $digest): void
    {
        $written = TransactionAmount::relate($minor, $currency, $minor, $currency)->toColumns()
            + ['fingerprint' => $digest];

        Transaction::query()->where('id', $id)->update($written);
    }

    // No tuple column moves, so no digest is owed.
    public function recategorize(int $id, int $categoryId): void
    {
        Transaction::query()->where('id', $id)->update(['category_id' => $categoryId]);
    }
}
