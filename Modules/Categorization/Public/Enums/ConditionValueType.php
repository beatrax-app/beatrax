<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

// Text covers every string field (merchant, description, counterparty); Amount
// and Date are their own.
enum ConditionValueType: string
{
    case Text = 'string';

    case Amount = 'amount';

    case Date = 'date';

    // Display order: the dropdown and the first-valid-operator fallback are both
    // built straight off this list.
    /** @return list<ConditionOperator> */
    public function operators(): array
    {
        return match ($this) {
            self::Text => [ConditionOperator::Contains, ConditionOperator::Equals, ConditionOperator::StartsWith],
            self::Amount => [ConditionOperator::GreaterThan, ConditionOperator::LessThan, ConditionOperator::Between, ConditionOperator::Equals],
            self::Date => [ConditionOperator::Before, ConditionOperator::After, ConditionOperator::Between],
        };
    }

    public static function coerce(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Text;
    }
}
