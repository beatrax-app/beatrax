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
        if ($priorProvenance === null || ($priorProvenance['source'] ?? null) !== 'rule') {
            return null;
        }

        $ruleId = self::intFrom($priorProvenance, 'rule_id');
        $oldCategoryId = self::intFrom($priorProvenance, 'category_id');

        // Every remaining way this is not a still-diverging rule suggestion:
        // no usable rule id, rule id 0 (which no row carries), no usable prior
        // category, or a new category that matches the old one and so has not
        // diverged from anything.
        if ($ruleId === null || $ruleId === 0 || $oldCategoryId === null || $newCategoryId === $oldCategoryId) {
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

    // An id out of a provenance payload, or null when the key is absent or
    // holds something that is not a number. The payload is JSON off a column,
    // so a numeric string is as legitimate as an int — both ids were read this
    // way, and reading it in one place is what keeps them agreeing.
    /**
     * @param  array<string, mixed>  $provenance
     */
    private static function intFrom(array $provenance, string $key): ?int
    {
        $raw = $provenance[$key] ?? null;

        return is_int($raw) || is_numeric($raw) ? (int) $raw : null;
    }
}
