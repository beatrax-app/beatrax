<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

// The one path that mutates transactions.counterparty_id on a user's behalf.
interface ReassignsCounterparty
{
    // Rows affected: 0 when either row is not the user's, the transaction is
    // reconciled, or the value is unchanged; 1 on success.
    public function __invoke(int $transactionId, int $counterpartyId, User $user): int;
}
