<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// Reconciled is the edit-lock state every write path guards against. The
// column and DTOs stay string; this enum is the canonical spelling callers
// map through, and the source Transaction::STATUSES is built from.
enum ClearedStatus: string
{
    case Uncleared = 'uncleared';

    case Cleared = 'cleared';

    case Reconciled = 'reconciled';

    // Clearing a row is a toggle, completing a reconciliation is one-way, and
    // the only exit from Reconciled is the reader's own un-reconcile. Nothing
    // reaches Reconciled from Uncleared: a row nobody confirmed against a
    // statement cannot be asserted as checked against one.
    /** @return list<self> */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Uncleared => [self::Cleared],
            self::Cleared => [self::Uncleared, self::Reconciled],
            self::Reconciled => [self::Cleared],
        };
    }

    // Read off the graph above rather than restated beside it, so a state added
    // there cannot leave a writer admitting an edge nobody drew. Two devices can
    // each take a legal step and land somewhere this refuses.
    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
