<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

// How a rule's conditions combine: All fires only when every condition
// matches (an empty condition set never fires); Any fires on the first match.
enum RuleCombinator: string
{
    case All = 'all';

    case Any = 'any';

    // What an unspecified or unrecognised stored combinator falls back to,
    // named once here rather than repeated as an 'all' literal at each read
    // boundary (a DB row, a hydrating form).
    public static function coerce(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::All;
    }
}
