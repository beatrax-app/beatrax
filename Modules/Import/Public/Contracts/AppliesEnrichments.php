<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\AppliedEnrichments;
use Modules\Import\Public\Dto\PendingEnrichment;

interface AppliesEnrichments
{
    // Implementations must be idempotent on a stored source_ref equal to the
    // incoming one, and row-lock each enrichment so concurrent importers
    // serialise instead of double-counting.

    // The booking terms every adopted restatement wrote are reported back
    // rather than announced here: this runs inside the confirm's transaction,
    // and an op written from in here outlives an import that rolls back.
    /**
     * @param  list<PendingEnrichment>  $enrichments
     */
    public function __invoke(array $enrichments, User $user): AppliedEnrichments;
}
