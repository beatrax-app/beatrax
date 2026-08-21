<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

// The one path that mutates transactions.note on a user's behalf.
interface SetsTransactionNote
{
    // Rows affected: 0 when the row is not the user's, is reconciled, or the
    // value is unchanged; 1 on success.
    public function __invoke(int $transactionId, ?string $text, string $mode, User $user): int;
}
