<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

use Modules\Core\Public\Support\Lang;

// Which operators are valid depends on the condition's value type — see
// ConditionValueType::operators().
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

    // The case maps to a stable translation key because the '>' / '<' backing
    // values cannot be lang-file keys.
    public function label(): string
    {
        $key = match ($this) {
            self::Contains => 'contains',
            self::Equals => 'equals',
            self::StartsWith => 'starts_with',
            self::GreaterThan => 'more_than',
            self::LessThan => 'less_than',
            self::Between => 'between',
            self::Before => 'before',
            self::After => 'after',
        };

        return Lang::get('categorization::operators.'.$key);
    }
}
