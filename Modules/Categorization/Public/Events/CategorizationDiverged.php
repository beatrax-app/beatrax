<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Events;

// Fires when the user reclassifies a transaction away from a still-active
// rule's suggestion. `$userId` lets the toast no-op on a cross-user event.
final class CategorizationDiverged
{
    public function __construct(
        public readonly int $transactionId,
        public readonly int $ruleId,
        public readonly int $oldCategoryId,
        public readonly int $newCategoryId,
        public readonly int $userId,
    ) {}

    // Both AssignCategory and TransactionDetail's re-dispatch route through
    // here, so the provenance shape is interpreted in exactly one place.
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

        // Rule id 0 belongs to no row, and a new category equal to the old one
        // has diverged from nothing.
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

    // The payload is JSON off a column, so a numeric string is as legitimate
    // as an int; reading both ids here is what keeps them agreeing.
    /**
     * @param  array<string, mixed>  $provenance
     */
    private static function intFrom(array $provenance, string $key): ?int
    {
        $raw = $provenance[$key] ?? null;

        return is_int($raw) || is_numeric($raw) ? (int) $raw : null;
    }
}
