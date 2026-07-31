<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

// What kind of value a condition compares, which decides both how the match
// is performed and which operators are offered. Text covers every string
// field (merchant, description, counterparty); Amount and Date are their own.
/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
enum ConditionValueType: string
{
    case Text = 'string';

    case Amount = 'amount';

    case Date = 'date';

    // The operators this value type allows, in display order — the shape the
    // form's operator dropdown and the first-valid-operator fallback are built
    // from, and the set the validator checks a submitted op against.
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
