<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

use Modules\Core\Public\Support\Lang;

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

    // How the operator is written in the rule form's operator dropdown. The
    // case maps to a stable translation key here — the '>' / '<' backing
    // values can't be keys — and the copy itself lives in the operators lang
    // file so the dropdown localises with the rest of the UI.
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
