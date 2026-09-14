<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Examples;

use Modules\Ledger\Models\Transaction;
use Modules\Ledger\Public\ValueObjects\TransactionAmount;

final class BadFingerprintTupleFixture
{
    // The spelling IcsSettlementAligner used: the amount columns arrive as a
    // value object's array, so no column name appears at the call site at all.
    public function rewriteAmount(int $id, int $minor, string $currency): void
    {
        Transaction::query()
            ->where('id', $id)
            ->update(TransactionAmount::relate($minor, $currency, $minor, $currency)->toColumns());
    }

    // Also a violation: counterparty_normalized is in the tuple as well, and
    // this one names the column outright rather than hiding it in an object.
    public function rewriteCounterparty(int $id, string $normalized): void
    {
        Transaction::query()
            ->where('id', $id)
            ->update(['counterparty_normalized' => $normalized]);
    }
}
