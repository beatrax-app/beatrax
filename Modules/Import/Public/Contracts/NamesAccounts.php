<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;

/**
 * @link ../../../../.docs/features/import/architecture.md#module-boundary
 */
interface NamesAccounts
{
    /**
     * @return int The id of the newly created Account row.
     */
    public function __invoke(string $iban, string $userSuppliedName, User $user): int;
}
