<?php

declare(strict_types=1);

namespace Modules\Auth\Internal\Support;

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

    // strtolower, not its mb_ sibling: login, Fortify and the console commands
    // all normalise this way, and a username stored under a different rule than
    // the one that looks it up cannot sign in.
    public static function normalize(string $input): string
    {
        return strtolower(trim($input));
    }

    public static function isValid(string $normalized): bool
    {
        return $normalized !== ''
            && mb_strlen($normalized) <= self::MAX_LENGTH
            && preg_match(self::PATTERN, $normalized) === 1;
    }
}
