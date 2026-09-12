<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;

// Every statement in the shipped tree that names the device_registry table, and
// whether it says anything about a retired row.
//
// The retirement stamp arrived with one reader taught about it, which is how the
// gap it was added to close stayed open: a row kept confirmed so a rebuild can
// verify what it signed was still, to fifty other queries, a device. Two answers
// are both right and they are opposite, so neither can be the default — a query
// that filters the stamp out of a history check makes a restored ledger
// unverifiable, and one that leaves it in a peer list hands a session to a
// machine that is gone.
//
// Read with the tokeniser rather than with a pattern: the table is named in
// prose all over this module, and half of that prose exists to say which map
// does NOT reach it.
final class DeviceRegistryQueries
{
    public const string TABLE = 'device_registry';

    // A statement that narrows to the self row cannot reach a retired one:
    // the retirement's whole mechanism is taking is_self off. That is a
    // decision the schema makes rather than one a reader has to restate.
    private const string NARROWED_TO_SELF = "/'is_self'\\s*(?:,|=>)\\s*(?:1|true)\\b/i";

    private const string RETIREMENT_MARKER = 'self_retired_at';

    private const string RETIREMENT_SEAM = 'stillADevice';

    /**
     * @return list<array{path: string, function: string, statement: string, decides: bool}>
     */
    public static function all(): array
    {
        $found = [];

        foreach (RepoTree::files(RepoTree::RUNTIME_DOMAIN_PHP) as $path) {
            $source = (string) file_get_contents($path);

            if (! str_contains($source, self::TABLE)) {
                continue;
            }

            $relative = str_replace(RepoTree::root().'/', '', $path);

            foreach (self::in($relative, $source) as $statement) {
                $found[] = $statement;
            }
        }

        return $found;
    }

    /**
     * @return list<array{path: string, function: string, statement: string, decides: bool}>
     */
    public static function in(string $path, string $source): array
    {
        $tokens = BackendSourceFiles::tokensOf($path, $source);
        $text = array_map(static fn (array|string $token): string => is_array($token) ? $token[1] : $token, $tokens);

        $found = [];

        foreach ($text as $index => $literal) {
            if ($literal !== "'".self::TABLE."'" && $literal !== '"'.self::TABLE.'"') {
                continue;
            }

            $statement = self::statementAround($text, $index);

            $found[] = [
                'path' => $path,
                'function' => self::functionAround($tokens, $text, $index),
                'statement' => $statement,
                'decides' => self::decides($statement),
            ];
        }

        return $found;
    }

    // Three spellings of one decision. The marker itself is the direct answer;
    // the seam is the registry's name for it; and narrowing to the self row
    // answers structurally, because the stamp and is_self are written together.
    public static function decides(string $statement): bool
    {
        return str_contains($statement, self::RETIREMENT_MARKER)
            || str_contains($statement, self::RETIREMENT_SEAM)
            || PatternScan::matches(self::NARROWED_TO_SELF, $statement);
    }

    public static function keyFor(string $path, string $function): string
    {
        return $path.'::'.$function;
    }

    /**
     * The whole statement the table name sits in, which is the span a filter
     * could be written into. A query builder reads left to right and a decorator
     * wraps it, so both directions are walked: `stillADevice(...)` stands before
     * the table name and `->whereNull(...)` after it.
     *
     * @param  list<string>  $text
     */
    private static function statementAround(array $text, int $index): string
    {
        $start = self::boundaryBefore($text, $index);
        $end = self::boundaryAfter($text, $index);

        $statement = implode('', array_slice($text, $start, $end - $start + 1));

        return trim(PatternScan::replace('/\s+/', ' ', $statement));
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

    private static function opens(string $token): bool
    {
        return $token === '(' || $token === '[' || $token === '{';
    }

    private static function closes(string $token): bool
    {
        return $token === ')' || $token === ']' || $token === '}';
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
    private static function functionAround(array $tokens, array $text, int $index): string
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

        return '{no function}';
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
}
