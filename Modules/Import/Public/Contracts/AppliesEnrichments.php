<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\PendingEnrichment;

interface AppliesEnrichments
{
    // Implementations must be idempotent on a stored source_ref equal to the
    // incoming one, and row-lock each enrichment so concurrent importers
    // serialise instead of double-counting.
    /**
     * @param  list<PendingEnrichment>  $enrichments
     * @return int Number of rows actually enriched (race-condition no-ops excluded).
     */
    public function __invoke(array $enrichments, User $user): int;
}
