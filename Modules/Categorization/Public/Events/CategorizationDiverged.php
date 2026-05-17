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
}
