<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The operator-facing half of a per-user tally. Console output is not reader
// copy and never goes through Lang, but "1 users" is still wrong, and three
// scheduled commands now say the same phrase.
final class CountedUsers
{
    public static function of(int $count): string
    {
        return $count === 1 ? '1 user' : $count.' users';
    }
}
