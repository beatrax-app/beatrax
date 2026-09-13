<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;

// The span a query builder chain occupies in a token stream, and the function
// it sits in. Two guards read a table name out of a statement and then ask what
// else that statement says, and the walk is the same walk for both: a builder
// reads left to right and a decorator wraps it, so a filter written into the
// chain can stand either side of the name the walk started from.
final class QueryStatements
{
    public const string NO_FUNCTION = '{no function}';

    /**
     * The whole statement the token at $index sits in, whitespace collapsed.
     *
     * @param  list<string>  $text
     */
    public static function around(array $text, int $index): string
    {
        [$start, $end] = self::boundsAround($text, $index);

        return trim(PatternScan::replace('/\s+/', ' ', implode('', array_slice($text, $start, $end - $start + 1))));
    }

    /**
     * @param  list<string>  $text
     * @return array{int, int}
     */
    public static function boundsAround(array $text, int $index): array
    {
        return [self::boundaryBefore($text, $index), self::boundaryAfter($text, $index)];
    }

    /**
     * The named function the statement sits in, found by walking back to the
     * nearest `function` keyword carrying a name. A closure in between is
     * `function (` and is stepped over, so a query written inside one is
     * attributed to the method that owns the closure rather than to nothing.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     */
    public static function functionAround(array $tokens, array $text, int $index): string
    {
        for ($at = $index - 1; $at >= 0; $at--) {
            $token = $tokens[$at];

            if (! is_array($token) || $token[0] !== T_FUNCTION) {
                continue;
            }

            $name = self::nameAfter($tokens, $text, $at);

            if ($name !== null) {
                return $name;
            }
        }

        return self::NO_FUNCTION;
    }

    /**
     * The method name the literal at $index is the first argument of, or null
     * where it is not an argument at all.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     */
    public static function calledMethodFor(array $tokens, array $text, int $index): ?string
    {
        $at = self::skipBlanksBefore($text, $index - 1);

        if ($at < 0 || $text[$at] !== '(') {
            return null;
        }

        $at = self::skipBlanksBefore($text, $at - 1);
        $token = $at < 0 ? null : $tokens[$at];

        return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
    }

    /**
     * The token index the receiver of the call at $index starts from, so a
     * caller can tell `$this->schema()->table('x')` from `$connection->table('x')`.
     *
     * @param  list<string>  $text
     */
    public static function receiverBefore(array $text, int $index): string
    {
        $at = self::skipBlanksBefore($text, $index - 1);
        $parts = [];

        for ($seen = 0; $at >= 0 && $seen < 6; $at--) {
            if (trim($text[$at]) === '') {
                continue;
            }

            $parts[] = $text[$at];
            $seen++;
        }

        return implode('', array_reverse($parts));
    }

    /**
     * The table name is an argument, so the walk starts INSIDE a group and has
     * to climb out of it: depth goes negative on the way and a boundary is
     * anything at or outside the level the statement itself sits at. A
     * separator deeper than that belongs to a closure or an array passed along
     * the chain, and stopping on one would read half a statement.
     *
     * @param  list<string>  $text
     */
    private static function boundaryBefore(array $text, int $index): int
    {
        $depth = 0;

        for ($at = $index - 1; $at >= 0; $at--) {
            $token = $text[$at];
            $depth += self::closes($token) ? 1 : (self::opens($token) ? -1 : 0);

            if ($depth <= 0 && ($token === ';' || $token === '{' || $token === '}')) {
                return $at + 1;
            }
        }

        return 0;
    }

    /**
     * A `{` closes the read as surely as a `;` does: a chain that ends in one
     * is a `foreach` header, and the block it opens is not part of the query.
     *
     * @param  list<string>  $text
     */
    private static function boundaryAfter(array $text, int $index): int
    {
        $depth = 0;
        $count = count($text);

        for ($at = $index + 1; $at < $count; $at++) {
            $token = $text[$at];
            $depth += self::opens($token) ? 1 : (self::closes($token) ? -1 : 0);

            if ($depth <= 0 && ($token === ';' || $token === '{')) {
                return $token === '{' ? $at - 1 : $at;
            }
        }

        return $count - 1;
    }

    /** @param  list<string>  $text */
    private static function skipBlanksBefore(array $text, int $at): int
    {
        while ($at >= 0 && trim($text[$at]) === '') {
            $at--;
        }

        return $at;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     */
    private static function nameAfter(array $tokens, array $text, int $at): ?string
    {
        for ($next = $at + 1, $count = count($tokens); $next < $count; $next++) {
            if (trim($text[$next]) === '' || $text[$next] === '&') {
                continue;
            }

            $token = $tokens[$next];

            return is_array($token) && $token[0] === T_STRING ? $token[1] : null;
        }

        return null;
    }

    private static function opens(string $token): bool
    {
        return $token === '(' || $token === '[' || $token === '{';
    }

    private static function closes(string $token): bool
    {
        return $token === ')' || $token === ']' || $token === '}';
    }
}
