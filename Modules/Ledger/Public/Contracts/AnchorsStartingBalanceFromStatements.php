<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

// An account created by a plain import has no baseline: only the onboarding
// wizard ever asked for one. Its statement carries an opening balance, and
// without it every balance the account reports is measured from zero.
interface AnchorsStartingBalanceFromStatements
{
    /**
     * @return int accounts anchored by this call
     */
    public function anchorForUser(User $user): int;
}
