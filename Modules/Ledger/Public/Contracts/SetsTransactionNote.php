<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

interface SetsTransactionNote
{
    // Rows affected: 0 when the row is not the user's, is reconciled, or the
    // value is unchanged; 1 on success.
    public function __invoke(int $transactionId, ?string $text, string $mode, User $user): int;
}
