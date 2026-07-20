<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Ledger\Models\Account;

/**
 * @link ../../../../.docs/features/import/architecture.md#key-services--events
 */
interface ResolvesKnownCounterpartyIban
{
    // Cross-module consumers (Transfers, Chains) depend on this Public
    // surface and never reach into Modules\Import\Internal\* directly.
    // Null when no alias exists, or the user owns no account of the
    // alias's target kind; lowest-id account wins on multiple matches.
    public function resolveAccount(string $iban, int $userId): ?Account;
}
