<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Counterparties\Public\Dto\CounterpartyMergeDto;

// Merging merchant aliases renames the value the resolver slugs a counterparty
// from, so the names that used to own a row stop owning one and the next import
// mints a second. The alias writer asks here to carry the history across.
interface MergesCounterparties
{
    /**
     * @param  list<string>  $formerNames
     */
    public function fold(User $user, array $formerNames, string $survivingName): CounterpartyMergeDto;
}
