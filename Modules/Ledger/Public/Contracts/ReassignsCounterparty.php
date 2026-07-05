<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Public contract for the one path that mutates
 * `transactions.counterparty_id` on behalf of a user (manual reassignment
 * today; the Plan 05 rule engine tomorrow). Ledger's `ReassignCounterparty`
 * action is bound as the default implementation.
 */
interface ReassignsCounterparty
{
    /**
     * Reassign the transaction's counterparty. Returns the number of rows
     * affected — 0 when the transaction does not belong to the user, the
     * target counterparty does not belong to the user, the row is
     * `reconciled` (D-08 lock), or the value is unchanged (write-only-on-
     * change); 1 on success.
     */
    public function __invoke(int $transactionId, int $counterpartyId, User $user): int;
}
