<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

enum NoteMode: string
{
    case Set = 'set';

    case Append = 'append';

    // A malformed stored mode writes rather than throws.
    public static function coerce(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Set;
    }
}
