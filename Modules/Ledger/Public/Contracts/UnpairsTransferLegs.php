<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Ledger\Public\Enums\TransactionType;

// What happens to the surviving half of a transfer when the other half is
// deleted: a transfer only means anything as a pair, so the partner stops
// being a transfer and becomes plain income or expense. Declared here and
// implemented by Transfers, which owns the pair rules.
interface UnpairsTransferLegs
{
    /**
     * @return TransactionType|null the survivor's new type, or null when nothing
     *                              was retyped — the survivor is gone too, it
     *                              still carries a pair link because something
     *                              re-paired it, or the deleted leg was not a
     *                              transfer
     */
    public function unpair(int $userId, int $survivorId, TransactionType $deletedType): ?TransactionType;
}
