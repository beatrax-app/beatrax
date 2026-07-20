<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\PendingEnrichment;

/**
 * @link ../../../../.docs/features/import/architecture.md#module-boundary
 */
interface AppliesEnrichments
{
    // Implementations MUST be idempotent (no-op + not counted when the
    // stored source_ref already equals the incoming ref) and wrap each
    // enrichment in its own row-locked transaction so concurrent
    // importers serialise or short-circuit rather than double-counting.
    /**
     * @param  list<PendingEnrichment>  $enrichments
     * @return int Number of rows actually enriched (race-condition no-ops excluded).
     */
    public function __invoke(array $enrichments, User $user): int;
}
