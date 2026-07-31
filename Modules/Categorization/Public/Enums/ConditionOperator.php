<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

// The comparison a condition applies. Which operators are valid depends on
// the condition's value type — see ConditionValueType::operators().
/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
enum ConditionOperator: string
{
    case Contains = 'contains';

    case Equals = 'equals';

    case StartsWith = 'starts_with';

    case GreaterThan = '>';

    case LessThan = '<';

    case Between = 'between';

    case Before = 'before';

    case After = 'after';

    // How the operator is written in the rule form's operator dropdown, kept
    // with the vocabulary rather than spelled out at the view.
    public function label(): string
    {
        return match ($this) {
            self::Contains => 'contains',
            self::Equals => 'equals',
            self::StartsWith => 'starts with',
            self::GreaterThan => 'more than',
            self::LessThan => 'less than',
            self::Between => 'between',
            self::Before => 'before',
            self::After => 'after',
        };
    }
}
