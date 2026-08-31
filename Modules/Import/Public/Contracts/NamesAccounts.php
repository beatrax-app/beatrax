<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;

interface NamesAccounts
{
    /**
     * @param  string|null  $statementCurrency  What the parsed file states this account is denominated in, or null when it states nothing a single currency covers.
     * @return int The id of the newly created Account row.
     */
    public function __invoke(string $iban, string $userSuppliedName, User $user, ?string $statementCurrency = null): int;
}
