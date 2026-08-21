<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Counterparties\Public\Dto\CounterpartyResolutionDto;
use Modules\Ledger\Public\Dto\CanonicalTransaction;

// Implementations MUST be side-effect-free on failure (the import
// pipeline cannot abort because a lookup threw) and MUST carry an
// explicit where('user_id', ...) filter on every counterparties read —
// BelongsToUser does not fire in the queue/console contexts this runs from.
interface CounterpartyResolver
{
    public function resolve(CanonicalTransaction $tx, User $user): ?CounterpartyResolutionDto;
}
