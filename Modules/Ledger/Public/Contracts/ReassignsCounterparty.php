<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

// The one path that mutates transactions.counterparty_id on behalf of
// a user. Ledger's ReassignCounterparty action is bound as the default
// implementation.
interface ReassignsCounterparty
{
    // Returns the number of rows affected — 0 when the transaction or
    // counterparty is not owned by the user, the row is reconciled, or
    // the value is unchanged; 1 on success.
    public function __invoke(int $transactionId, int $counterpartyId, User $user): int;
}
