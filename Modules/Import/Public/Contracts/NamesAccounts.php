<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;

/**
 * Creates an Account row for an unknown IBAN encountered during preview.
 * The wizard invokes this contract when the user supplies a name for a
 * counterparty that does not yet exist in the user's `accounts` table.
 */
interface NamesAccounts
{
    /**
     * @return int The id of the newly created Account row.
     */
    public function __invoke(string $iban, string $userSuppliedName, User $user): int;
}
