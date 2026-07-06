<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Pipeline;

use Modules\Migration\Public\Dto\ConflictDto;

/**
 * Outcome of `ThreeWayMergeResolver::resolve()` (Req 10, D-11/D-12/D-13): the
 * partition of every already-mapped, newly-re-parsed source entity into an
 * apply-set (source changed, beatrax unchanged since the last import — safe
 * to apply) and a conflict-set (both source AND beatrax diverged from the
 * stored baseline — apply nothing, list for the user). Entities with no
 * existing `migration_source_map` row at all are NOT represented here — they
 * are brand-new source rows that `PromoteStagingToDomain::promote()`'s own
 * per-entity resolve-gate already creates normally (Req 9 idempotency still
 * applies to them).
 */
final class MergeDecision
{
    /**
     * @param  list<array{entityType: string, sourceExternalId: string, fields: array<string, string|int|float|bool|null>}>  $applies
     * @param  list<ConflictDto>  $conflicts
     */
    public function __construct(
        public readonly array $applies,
        public readonly array $conflicts,
    ) {}

    /**
     * The composite `{categoryExternalId}|{period_start}` keys of every
     * budget-assignment conflict — the one entity kind
     * `PromoteStagingToDomain::promoteBudgetAssignments()` applies
     * unconditionally (D-06 value-idempotent `setAssigned()`), so these keys
     * are threaded back in as an explicit skip-list to keep a conflicted row
     * byte-for-byte untouched (D-12/D-13) rather than silently overwritten.
     *
     * @return list<string>
     */
    public function conflictedBudgetAssignmentKeys(): array
    {
        $keys = [];
        foreach ($this->conflicts as $conflict) {
            if ($conflict->entityType === 'budget_assignment' && $conflict->sourceExternalId !== null) {
                $keys[] = $conflict->sourceExternalId;
            }
        }

        return $keys;
    }
}
