<?php

declare(strict_types=1);

namespace Modules\Counterparties\Internal\Enums;

use Modules\Counterparties\Public\Enums\CounterpartyType;

// The chip row and the `?type=` parameter speak this vocabulary, and it is not
// the column's: `all` matches every row rather than any stored value, and the
// self-account chip is spelled `self` here where the column stores
// `self_account`. toColumnValue() is the only place that translation happens.
enum CounterpartyTypeFilter: string
{
    case All = 'all';

    case Merchant = 'merchant';

    case Personal = 'personal';

    case Bank = 'bank';

    case Government = 'government';

    case SelfAccount = 'self';

    case Unknown = 'unknown';

    public function toColumnValue(): ?CounterpartyType
    {
        return match ($this) {
            self::All => null,
            self::Merchant => CounterpartyType::Merchant,
            self::Personal => CounterpartyType::Personal,
            self::Bank => CounterpartyType::Bank,
            self::Government => CounterpartyType::Government,
            self::SelfAccount => CounterpartyType::SelfAccount,
            self::Unknown => CounterpartyType::Unknown,
        };
    }

    public static function forColumnValue(CounterpartyType $type): self
    {
        return match ($type) {
            CounterpartyType::Merchant => self::Merchant,
            CounterpartyType::Personal => self::Personal,
            CounterpartyType::Bank => self::Bank,
            CounterpartyType::Government => self::Government,
            CounterpartyType::SelfAccount => self::SelfAccount,
            CounterpartyType::Unknown => self::Unknown,
        };
    }
}
