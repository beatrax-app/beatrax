<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Ledger\Models\Account;

/**
 * @link ../../../../.docs/features/import/architecture.md#key-services--events
 */
interface ResolvesKnownCounterpartyIban
{
    // Null when no alias exists, or the user owns no account of the alias's
    // target kind. Lowest-id account wins on several matches.
    public function resolveAccount(string $iban, int $userId): ?Account;
}
