<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Enums;

// The kind of entity a counterparties row represents. The column stays
// string (enforced by a trigger); this enum is the one canonical spelling
// callers map through. The `all`/`self` UI-filter aliases are not stored
// values and live only in the index/profile view layer.
/**
 * @link ../../../../.docs/features/counterparties/architecture.md
 */
enum CounterpartyType: string
{
    case Merchant = 'merchant';

    case Personal = 'personal';

    case Bank = 'bank';

    case Government = 'government';

    case SelfAccount = 'self_account';

    case Unknown = 'unknown';
}
