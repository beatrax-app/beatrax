<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

interface DeletesTransaction
{
    // The row, its legs, its search shadow and the retype of a transfer's
    // surviving partner are one transaction, and every mutation event is
    // dispatched only after it commits. Missing, foreign and reconciled share
    // one answer: telling them apart would confirm the row exists.
    public function delete(User $user, int $transactionId): bool;
}
