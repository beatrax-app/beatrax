<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Contracts;

use Modules\Core\Models\User;

// The one path that mutates transactions.note on behalf of a user.
// Ledger's SetTransactionNote action is bound as the default
// implementation.
interface SetsTransactionNote
{
    // Set (mode='set') or append onto (mode='append') the note.
    // Returns rows affected — 0 when not owned by the user, the row is
    // reconciled, or the value is unchanged; 1 on success.
    public function __invoke(int $transactionId, ?string $text, string $mode, User $user): int;
}
