<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

/**
 * Dispatched after AssignCategory detects that the user has reclassified
 * a transaction whose initial suggestion came from a rule (i.e. prior
 * `auto_category_provenance.source === 'rule'`) AND the new category
 * differs from the rule's category.
 *
 * Consumers: the CorrectionDivergenceToast SFC bridges this event to a
 * Livewire-local notification asking the user to either Update the rule
 * to the new category or Keep the current rule. Analytics or
 * audit-log listeners can also subscribe without touching the toast
 * surface.
 *
 * Payload shape:
 *  - `$transactionId`  — the reclassified transaction's id
 *  - `$ruleId`         — the rule whose suggestion was overridden
 *  - `$oldCategoryId`  — the rule's category before reclassify
 *  - `$newCategoryId`  — the user's chosen category
 *  - `$userId`         — the owner of the transaction + rule
 *
 * `$userId` is carried explicitly so the downstream toast SFC can
 * perform a defensive cross-user guard (`$userId !== currentUser->id`
 * → no-op).
 */
final class CategorizationDiverged
{
    public function __construct(
        public readonly int $transactionId,
        public readonly int $ruleId,
        public readonly int $oldCategoryId,
        public readonly int $newCategoryId,
        public readonly int $userId,
    ) {}

    /**
     * Build a `CategorizationDiverged` event from a decoded prior
     * `auto_category_provenance` array, or return null when the
     * provenance does not represent a rule-driven suggestion whose
     * category the user just overrode.
     *
     * This is the single canonical detector. Both `AssignCategory`
     * (the framework-event dispatcher) and `TransactionDetail`'s
     * Livewire-local re-dispatch route their detection through here so
     * the validation logic stays in lockstep — any future provenance
     * shape change is applied once.
     *
     * Returns null when:
     *  - `$priorProvenance` is null (no prior auto-categorization), OR
     *  - `source` !== 'rule' (memory-driven or manual provenance), OR
     *  - `rule_id` is missing or non-numeric or zero, OR
     *  - `category_id` is missing or non-numeric, OR
     *  - `$newCategoryId` equals the prior `category_id` (no divergence).
     *
     * @param  array<string, mixed>|null  $priorProvenance
     */
    public static function fromProvenance(
        ?array $priorProvenance,
        int $transactionId,
        int $newCategoryId,
        int $userId,
    ): ?self {
        if ($priorProvenance === null) {
            return null;
        }

        $source = $priorProvenance['source'] ?? null;
        if ($source !== 'rule') {
            return null;
        }

        $ruleIdRaw = $priorProvenance['rule_id'] ?? null;
        if (! is_int($ruleIdRaw) && ! is_numeric($ruleIdRaw)) {
            return null;
        }
        $ruleId = (int) $ruleIdRaw;
        if ($ruleId === 0) {
            return null;
        }

        $oldCategoryRaw = $priorProvenance['category_id'] ?? null;
        if (! is_int($oldCategoryRaw) && ! is_numeric($oldCategoryRaw)) {
            return null;
        }
        $oldCategoryId = (int) $oldCategoryRaw;

        if ($newCategoryId === $oldCategoryId) {
            return null;
        }

        return new self(
            transactionId: $transactionId,
            ruleId: $ruleId,
            oldCategoryId: $oldCategoryId,
            newCategoryId: $newCategoryId,
            userId: $userId,
        );
    }
}
