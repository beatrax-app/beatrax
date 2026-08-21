<?php

declare(strict_types=1);

namespace Modules\Counterparties\Public\Enums;

// The column stays a string (a trigger enforces the vocabulary); this enum is
// the one canonical spelling callers map through. The `all`/`self` UI-filter
// aliases are never stored, and live only in the index/profile view layer.
enum CounterpartyType: string
{
    case Merchant = 'merchant';

    case Personal = 'personal';

    case Bank = 'bank';

    case Government = 'government';

    case SelfAccount = 'self_account';

    case Unknown = 'unknown';
}
