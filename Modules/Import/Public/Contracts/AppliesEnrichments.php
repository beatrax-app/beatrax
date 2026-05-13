<?php

declare(strict_types=1);

namespace Modules\Import\Public\Contracts;

use Modules\Core\Models\User;
use Modules\Import\Public\Dto\PendingEnrichment;

/**
 * Applies a buffered list of cross-format enrichments to existing
 * transactions rows: each PendingEnrichment results in an UPDATE that
 * writes the new (stronger) source_ref and appends a provenance entry
 * to the enriched_from JSON column.
 *
 * Implementations MUST be idempotent: if the existing row's source_ref
 * already equals the incoming new ref, the operation is a no-op and
 * does NOT count toward the return value. Each enrichment is wrapped
 * in its own DB transaction with a row-level lock so concurrent
 * importers either serialise or short-circuit on the ref-equality
 * check instead of double-counting.
 */
interface AppliesEnrichments
{
    /**
     * @param  list<PendingEnrichment>  $enrichments
     * @return int Number of rows actually enriched (race-condition no-ops excluded).
     */
    public function __invoke(array $enrichments, User $user): int;
}
