<?php

declare(strict_types=1);

namespace Modules\Categorization\Public\Enums;

// How a note action writes: Set replaces the transaction note, Append adds
// to whatever is already there.
/**
 * @link ../../../../.docs/features/categorization/architecture.md
 */
enum NoteMode: string
{
    case Set = 'set';

    case Append = 'append';

    // What an unspecified or unrecognised stored mode falls back to, so a
    // malformed payload writes rather than throws.
    public static function coerce(?string $value): self
    {
        return self::tryFrom($value ?? '') ?? self::Set;
    }
}
