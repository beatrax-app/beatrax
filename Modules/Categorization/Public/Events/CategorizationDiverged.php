<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

// Fires when the user reclassifies a transaction whose initial suggestion
// came from a still-active rule to a DIFFERENT category. The
// CorrectionDivergenceToast SFC bridges this to an Update-rule/Keep-current
// prompt; `$userId` lets it defensively no-op on a cross-user event.
final class CategorizationDiverged
{
    public function __construct(
        public readonly int $transactionId,
        public readonly int $ruleId,
        public readonly int $oldCategoryId,
        public readonly int $newCategoryId,
        public readonly int $userId,
    ) {}

    // The single canonical detector: both AssignCategory and
    // TransactionDetail's Livewire-local re-dispatch route through here so
    // a future provenance shape change is applied once. Returns null when
    // the prior provenance is not a still-diverging rule suggestion.
    /**
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
