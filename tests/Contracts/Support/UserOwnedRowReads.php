<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Illuminate\Database\Connection;
use Modules\Core\Public\Support\PatternScan;

// A row in a user-owned table belongs to whoever the `user_id` column names, and
// a primary key reaching a query is not evidence of that: ids are derived per
// device and no foreign key ties one back to a reader. So a statement that names
// a table and never names an owner returns whatever matched, whoever owns it.
final class UserOwnedRowReads
{
    // `SplitLegs::ownedBy` reaches the owner through the parent transaction,
    // which is where a leg's ownership is actually stored. `scopeToUser` is the
    // sync applier's spelling of the same question, and it refuses outright
    // rather than widening when a table has no owner it can reach.
    public const array OWNERSHIP_HELPERS = ['SplitLegs::ownedBy(', '->scopeToUser('];

    // Not `origin_user_id`, which is provenance on another account's replicated
    // entry: the lookbehind refuses a leading word character, while
    // `transactions.user_id` and `t.user_id` both pass because a dot is neither.
    private const string OWNER_COLUMN = '/(?<![A-Za-z0-9_])user_id$/';

    // The column compared inside a raw SQL fragment. A mention is not enough
    // there either -- it has to be one side of a comparison.
    private const string OWNER_IN_RAW_SQL = '/(?<![A-Za-z0-9_])user_id(?![A-Za-z0-9_])\s*(?:=|<|>|!|\bIN\b|\bIS\b)/i';

    // Where a bare column name becomes a predicate. `select`, `pluck` and
    // `value` are deliberately absent: reading the owner column out of a row is
    // the opposite of bounding the read by it, and twelve statements passed a
    // looser rule on exactly that -- `->where('id', $inboxId)->value('user_id')`
    // among them.
    private const string PREDICATE_METHOD = '/^(?:where|orWhere|on|orOn|having|orHaving)/i';

    // `table('transactions as t')` names the same table as `table('transactions')`.
    private const string ALIAS_SUFFIX = '/\s+as\s+/i';

    /**
     * Every table carrying a `user_id`, read off the live schema rather than
     * written down. The same derivation UserScopedDataPurge makes, for the same
     * reason it makes it: a hand-kept list goes stale the first time a module
     * adds a table, and it goes stale silently.
     *
     * @link ../../../.docs/conventions/arch-invariants.md#a-read-of-a-user-owned-row-carries-its-reader
     *
     * @return list<string>
     */
    public static function ownedTables(Connection $connection): array
    {
        $schema = $connection->getSchemaBuilder();
        $owned = [];

        foreach ($schema->getTableListing() as $table) {
            $name = str_contains($table, '.') ? substr($table, (int) strrpos($table, '.') + 1) : $table;

            if ($name !== 'users' && in_array('user_id', $schema->getColumnListing($name), true)) {
                $owned[] = $name;
            }
        }

        sort($owned);

        return $owned;
    }

    /**
     * @param  list<string>  $tables
     * @return list<array{line: int, table: string}> every statement naming one of $tables and no owner
     */
    public static function unscopedIn(string $source, array $tables): array
    {
        $found = [];

        foreach (self::statementsIn($source, $tables) as $statement) {
            if (! self::namesAnOwner($statement['text'])) {
                $found[] = ['line' => $statement['line'], 'table' => $statement['table']];
            }
        }

        return $found;
    }

    /**
     * @param  list<string>  $tables
     * @return list<array{line: int, table: string, text: string, scoped: bool}>
     */
    public static function statementsIn(string $source, array $tables): array
    {
        $tokens = token_get_all($source);
        $count = count($tokens);
        $statements = [];

        for ($index = 0; $index < $count; $index++) {
            $named = self::tableNamedAt($tokens, $index);

            if ($named === null || ! in_array($named, $tables, true)) {
                continue;
            }

            $text = self::scopeTextAround($tokens, $count, $index);

            $statements[] = [
                'line' => is_array($tokens[$index]) ? $tokens[$index][2] : 0,
                'table' => $named,
                'text' => $text,
                'scoped' => self::namesAnOwner($text),
            ];
        }

        return $statements;
    }

    /**
     * Whether the statement CONSTRAINS by an owner, rather than merely
     * mentioning one. Three spellings count: the column as the first argument
     * of a where/join/having, the column as a key in a written row, and the
     * column compared inside a raw fragment. A named helper counts too, because
     * both of them end in a predicate on somebody's `user_id`.
     */
    public static function namesAnOwner(string $text): bool
    {
        foreach (self::OWNERSHIP_HELPERS as $helper) {
            if (str_contains($text, $helper)) {
                return true;
            }
        }

        $tokens = token_get_all('<?php '.$text.';');
        $count = count($tokens);

        for ($index = 0; $index < $count; $index++) {
            $token = $tokens[$index];

            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $literal = trim($token[1], "'\"");

            if (preg_match(self::OWNER_COLUMN, $literal) !== 1) {
                if (preg_match(self::OWNER_IN_RAW_SQL, $literal) === 1) {
                    return true;
                }

                continue;
            }

            if (self::writtenAsAKey($tokens, $count, $index) || self::comparedByAPredicate($tokens, $index)) {
                return true;
            }
        }

        return false;
    }

    /**
     * `['user_id' => $userId]`, which is how an insert, an update and an
     * updateOrInsert identity all name the owner.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function writtenAsAKey(array $tokens, int $count, int $index): bool
    {
        for ($ahead = $index + 1; $ahead < $count; $ahead++) {
            $token = $tokens[$ahead];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token) && $token[0] === T_DOUBLE_ARROW;
        }

        return false;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function comparedByAPredicate(array $tokens, int $index): bool
    {
        if (($tokens[$index - 1] ?? null) !== '(') {
            return false;
        }

        for ($back = $index - 2; $back >= 0; $back--) {
            $token = $tokens[$back];

            if (is_array($token) && $token[0] === T_WHITESPACE) {
                continue;
            }

            return is_array($token)
                && $token[0] === T_STRING
                && preg_match(self::PREDICATE_METHOD, $token[1]) === 1;
        }

        return false;
    }

    /**
     * `->table('x')` / `->from('x')` with the literal as the sole argument, the
     * alias suffix dropped. A call passing the name as one of several arguments
     * is a different call, and `from($subquery)` names no table at all.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function tableNamedAt(array $tokens, int $index): ?string
    {
        $token = $tokens[$index];

        if (! is_array($token) || $token[0] !== T_STRING || ! in_array($token[1], ['table', 'from'], true)) {
            return null;
        }

        if (($tokens[$index + 1] ?? null) !== '('
            || ! is_array($tokens[$index + 2] ?? null)
            || $tokens[$index + 2][0] !== T_CONSTANT_ENCAPSED_STRING
            || ($tokens[$index + 3] ?? null) !== ')') {
            return null;
        }

        $literal = trim($tokens[$index + 2][1], "'\"");

        return strtolower(PatternScan::split(self::ALIAS_SUFFIX, $literal)[0] ?? $literal);
    }

    /**
     * The text an owner may be named in: the whole statement, plus every later
     * statement in the same block that uses the variable this one was assigned
     * to. Four readers build the query in one statement and scope it in the
     * next — `$joined = …->table('transactions')…;` then `…->where('transactions.user_id', …)`
     * — and reading the first alone reports all four, hiding the one that
     * really never scopes.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function scopeTextAround(array $tokens, int $count, int $index): string
    {
        $start = self::statementStart($tokens, $index);
        $end = self::statementEnd($tokens, $count, $index);
        $text = self::textOf($tokens, $start, $end);

        $assigned = self::variableAssignedAt($tokens, $start);

        if ($assigned === null) {
            return $text;
        }

        return $text.self::laterUsesOf($tokens, $count, $end + 1, $assigned);
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function statementStart(array $tokens, int $index): int
    {
        $depth = 0;

        for ($back = $index; $back >= 0; $back--) {
            $token = $tokens[$back];

            if ($token === ')') {
                $depth++;
            } elseif ($token === '(') {
                $depth--;
            } elseif ($depth <= 0 && ($token === ';' || $token === '{' || $token === '}' || $token === ',')) {
                return $back + 1;
            } elseif ($depth <= 0 && is_array($token) && $token[0] === T_OPEN_TAG) {
                return $back + 1;
            }
        }

        return 0;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function statementEnd(array $tokens, int $count, int $index): int
    {
        $depth = 0;

        for ($ahead = $index; $ahead < $count; $ahead++) {
            $token = $tokens[$ahead];

            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
            } elseif ($token === ';' && $depth <= 0) {
                return $ahead;
            }
        }

        return $count - 1;
    }

    /**
     * The variable a statement opens by assigning to, which is the only shape
     * whose scope can legitimately be named somewhere else.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function variableAssignedAt(array $tokens, int $start): ?string
    {
        $meaningful = [];

        for ($at = $start; isset($tokens[$at]) && count($meaningful) < 2; $at++) {
            $token = $tokens[$at];

            // Bounded by what it is looking for rather than by a token count:
            // the three comment lines above one of these assignments pushed the
            // `=` past an offset cap, and the chase then never started.
            if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_ATTRIBUTE, T_OPEN_TAG], true)) {
                continue;
            }

            $meaningful[] = $token;
        }

        $first = $meaningful[0] ?? null;

        if (! is_array($first) || $first[0] !== T_VARIABLE || ($meaningful[1] ?? null) !== '=') {
            return null;
        }

        return $first[1];
    }

    /**
     * Every later statement in the same brace block that names $variable. The
     * block is the bound: a name reused in the next method is a different query,
     * and a walk that ran to the end of the file would let it answer for this one.
     *
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function laterUsesOf(array $tokens, int $count, int $from, string $variable): string
    {
        $braces = 0;
        $parens = 0;
        $statement = '';
        $uses = false;
        $text = '';

        for ($ahead = $from; $ahead < $count; $ahead++) {
            $token = $tokens[$ahead];

            if ($token === '(') {
                $parens++;
            } elseif ($token === ')') {
                $parens--;
            } elseif ($token === '{' || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $braces++;
            } elseif ($token === '}') {
                if ($braces === 0) {
                    break;
                }

                $braces--;
            }

            if (is_array($token) && $token[0] === T_VARIABLE && $token[1] === $variable) {
                $uses = true;
            }

            $statement .= is_array($token) ? $token[1] : $token;

            if ($token === ';' && $parens <= 0 && $braces === 0) {
                $text .= $uses ? $statement : '';
                $statement = '';
                $uses = false;
            }
        }

        return $text;
    }

    /**
     * @param  list<array{0: int, 1: string, 2: int}|string>  $tokens
     */
    private static function textOf(array $tokens, int $start, int $end): string
    {
        $text = '';

        for ($at = $start; $at <= $end && isset($tokens[$at]); $at++) {
            $text .= is_array($tokens[$at]) ? $tokens[$at][1] : $tokens[$at];
        }

        return $text;
    }
}
