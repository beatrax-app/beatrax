<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Enums;

// The clearing lifecycle uncleared -> cleared -> reconciled, where
// reconciled is the edit-lock state every write path guards against. The
// column and DTOs stay string; this enum is the canonical spelling callers
// map through, and the source Transaction::STATUSES is built from.
/**
 * @link ../../../../.docs/features/ledger/architecture.md
 */
enum ClearedStatus: string
{
    case Uncleared = 'uncleared';

    case Cleared = 'cleared';

    case Reconciled = 'reconciled';
}
