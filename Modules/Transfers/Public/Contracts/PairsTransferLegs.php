<?php

declare(strict_types=1);

namespace Modules\Transfers\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Ledger\Models\Transaction;

/**
 * @link ../../../../.docs/features/transfers/architecture.md
 */
interface PairsTransferLegs
{
    /**
     * @return int|null The partner transaction id when a pair was formed;
     *                  null when the row is not a transfer leg, is already
     *                  paired, or no partner is yet persisted.
     */
    public function pairOne(Transaction $tx, User $user): ?int;

    /**
     * @return int The number of NEW pair links written.
     */
    public function pairOrphansForUser(User $user): int;
}
