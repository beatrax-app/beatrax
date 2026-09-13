<?php

declare(strict_types=1);

namespace Modules\Core\Tests\Support;

use Pdo\Sqlite;

// A real Pdo\Sqlite that declines to register a user function. SQLite returns
// false here rather than raising -- an arity it will not accept is one way to
// see it -- and the production call passes an arity it always accepts, so this
// is the only way to reach the refusal with the argument the app really sends.
final class RefusingSqlitePdo extends Sqlite
{
    public function createFunction(string $function_name, callable $callback, int $num_args = -1, int $flags = 0): bool
    {
        return false;
    }
}
