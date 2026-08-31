<?php

declare(strict_types=1);

namespace Modules\Auth\Public\Support;

// What a username may be, in one place: every account-creating path lowercases
// and trims it the same way, and the recovery-codes download puts the result in
// a filename, where a slash, a control character or 400 bytes of emoji is not a
// name but a broken write.
final class Username
{
    public const int MAX_LENGTH = 32;

    // Letters and digits in any script, plus the three separators a handle
    // conventionally carries. Whitespace, path separators, quotes and emoji
    // are all outside it.
    private const string PATTERN = '/^[\p{L}\p{N}]([\p{L}\p{N}._-]*[\p{L}\p{N}])?$/u';

    // mb_strtolower: the pattern admits \p{L} in any script, strtolower folds
    // ASCII only, and Fortify's own CanonicalizeUsername folds multibyte. A
    // name stored under one rule and looked up under the other cannot sign
    // in.
    public static function normalize(string $input): string
    {
        return mb_strtolower(trim($input));
    }

    public static function isValid(string $normalized): bool
    {
        return $normalized !== ''
            && mb_strlen($normalized) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $normalized) === 1;
    }
}
