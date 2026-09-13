<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;

// Every predicate in the shipped tree that narrows on a column a migration
// added to a table that already existed, and whether it names the table it
// belongs to.
//
// Those columns are the ones a database can be without. A column declared in
// the original `create` cannot go missing while its table is there, and a
// missing table raises; a column added by an ALTER is absent on every copy the
// migration has not reached, and SQLite reads the double-quoted name of an
// absent column as a string LITERAL rather than raising. So the clause is
// silently true or silently false, and its answer is indistinguishable from a
// real one.
//
// Read with the tokeniser rather than with a pattern, for the reason the
// registry walk beside this one is: these column names are written in prose all
// over the tree, and a migration's own name is frequently one of them.
/**
 * @link ../../../.docs/conventions/a-predicate-on-a-column-that-is-not-there.md
 */
final class AlteredColumnPredicates
{
    // `where` and `having`, in every spelling the builder offers. A projection
    // is silent on an absent column too and is deliberately NOT here: it hands
    // back the column's own name rather than a wrong verdict, and qualifying
    // one renames the property the row comes back under. The page linked above
    // carries that half.
    private const string PREDICATE = '/^(?:or)?(?:where|having)[A-Za-z]*$/i';

    // `table(...)` and `from(...)` are the two ways a builder is opened on a
    // name. A model-backed read never spells the table, so it is not collected
    // and nothing is lost by requiring one of these.
    private const string OPENS_A_BUILDER = '/^(?:table|from)$/';

    private const string SCHEMA_BUILDER = '/schema\(\)->|Schema::/';

    private const string BLUEPRINT = '$table->';

    private const string A_BARE_NAME = "/^'([a-z0-9_]+)'$/";

    // A name the builder was opened on may carry an alias -- `table('x as c')`
    // -- and the alias, not the table, is what a qualified column has to use.
    private const string ALIASED = '/\s+as\s+/i';

    // The Blueprint calls that name a column already on the table, or take one
    // away. Everything else on that surface declares one.
    private const array DECLARES_NO_COLUMN = [
        'after', 'change', 'comment', 'default', 'dropColumn', 'dropForeign',
        'dropIndex', 'dropPrimary', 'dropUnique', 'foreign', 'fullText',
        'index', 'primary', 'renameColumn', 'spatialIndex', 'unique',
    ];

    // Walked once: the migration set is three hundred files and every caller
    // below asks for the same answer.
    /** @var array<string, array<string, string>>|null */
    private static ?array $columns = null;

    /**
     * The columns each ALTER migration adds, keyed by table then column, with
     * the migration that added it.
     *
     * @return array<string, array<string, string>>
     */
    public static function columns(): array
    {
        if (self::$columns !== null) {
            return self::$columns;
        }

        $columns = [];

        foreach (self::migrationFiles() as $path) {
            $relative = str_replace(RepoTree::root().'/', '', $path);

            foreach (self::alteredIn($relative, (string) file_get_contents($path)) as [$table, $column]) {
                $columns[$table][$column] = basename($path);
            }
        }

        ksort($columns);

        return self::$columns = $columns;
    }

    /**
     * @return list<array{path: string, function: string, table: string, column: string, method: string, qualified: bool, asked: bool}>
     */
    public static function all(): array
    {
        $columns = self::columns();
        $found = [];

        foreach (RepoTree::files(RepoTree::RUNTIME_DOMAIN_PHP) as $path) {
            $source = (string) file_get_contents($path);

            if (! self::mayNameATable($source, $columns)) {
                continue;
            }

            $source = SyncedColumnWrites::withTableConstantsResolved($source);

            foreach (self::in(str_replace(RepoTree::root().'/', '', $path), $source, $columns) as $site) {
                $found[] = $site;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, array<string, string>>  $columns
     * @return list<array{path: string, function: string, table: string, column: string, method: string, qualified: bool, asked: bool}>
     */
    public static function in(string $path, string $source, array $columns): array
    {
        $tokens = BackendSourceFiles::tokensOf($path, $source);
        $text = self::textOf($tokens);
        $held = [];
        $found = [];

        foreach (array_keys($text) as $index) {
            $opened = self::tableOpenedAt($tokens, $text, $index, $columns)
                ?? self::tableHeldAt($tokens, $text, $index, $held);

            if ($opened === null) {
                continue;
            }

            [$start, $end] = QueryStatements::boundsAround($text, $index);
            $function = QueryStatements::functionAround($tokens, $text, $index);
            self::holdAssignedBuilder($text, $start, $function, $opened, $held);

            foreach (self::predicatesIn($tokens, $text, [$start, $end], $opened, $columns) as $site) {
                $found[] = [
                    'path' => $path,
                    'function' => $function,
                    ...$site,
                    'asked' => self::asks($source, $site['table'], $site['column']),
                ];
            }
        }

        return $found;
    }

    // A chain assigned to a variable and narrowed on the next line is the same
    // statement to a reader and two to a token walk, and the second half names
    // no table at all. Held per function, because one method's `$query` is not
    // the next one's.
    /**
     * @param  list<string>  $text
     * @param  array{table: string, scope: string}  $opened
     * @param  array<string, array{table: string, scope: string}>  $held
     */
    private static function holdAssignedBuilder(array $text, int $start, string $function, array $opened, array &$held): void
    {
        $at = self::nextWritten($text, $start);
        $name = $text[$at] ?? '';
        $assigns = $text[self::nextWritten($text, $at + 1)] ?? '';

        if (! str_starts_with($name, '$') || $assigns !== '=') {
            return;
        }

        $held[$function."\0".$name] = $opened;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     * @param  array<string, array{table: string, scope: string}>  $held
     * @return array{table: string, scope: string}|null
     */
    private static function tableHeldAt(array $tokens, array $text, int $index, array $held): ?array
    {
        $name = $text[$index];

        if (! str_starts_with($name, '$') || ! self::startsAStatement($text, $index)) {
            return null;
        }

        return $held[QueryStatements::functionAround($tokens, $text, $index)."\0".$name] ?? null;
    }

    /** @param  list<string>  $text */
    private static function nextWritten(array $text, int $at): int
    {
        $count = count($text);

        while ($at < $count && trim($text[$at]) === '') {
            $at++;
        }

        return $at;
    }

    /** @param  list<string>  $text */
    private static function startsAStatement(array $text, int $index): bool
    {
        for ($at = $index - 1; $at >= 0; $at--) {
            if (trim($text[$at]) === '') {
                continue;
            }

            return in_array($text[$at], [';', '{', '}'], true);
        }

        return false;
    }

    // Whether the file asks the database about this exact column of this exact
    // table. Asked per column rather than per file on purpose: a whole-file
    // substring is how an exemption earned once comes to excuse everything
    // written beside it afterwards.
    public static function asks(string $source, string $table, string $column): bool
    {
        return PatternScan::matches(
            '/missingColumns\([^;]{0,300}?\''.preg_quote($table, '/').'\'[^;]{0,400}?\''.preg_quote($column, '/').'\'/s',
            $source,
        );
    }

    // The substring every walk below needs, asked once per file rather than
    // once per table: the scan is forty tables wide over three thousand files,
    // and a tokeniser run on a file naming none of them is the whole cost.
    /** @param  array<string, array<string, string>>  $columns */
    private static function mayNameATable(string $source, array $columns): bool
    {
        return array_any(
            array_keys($columns),
            static fn (string $table): bool => str_contains($source, "'".$table),
        );
    }

    /** @return list<string> */
    private static function migrationFiles(): array
    {
        return array_values(array_filter(
            RepoTree::files(RepoTree::EVERY_PHP_FILE),
            static fn (string $path): bool => str_contains($path, '/Database/Migrations/')
                || str_contains($path, '/database/migrations/'),
        ));
    }

    /**
     * @return list<array{string, string}>
     */
    private static function alteredIn(string $path, string $source): array
    {
        $tokens = BackendSourceFiles::tokensOf($path, $source);
        $text = self::textOf($tokens);
        $found = [];
        $altering = null;

        foreach (array_keys($text) as $index) {
            $target = self::schemaTargetAt($tokens, $text, $index);

            if ($target !== null) {
                $altering = $target['alters'] ? $target['table'] : null;

                continue;
            }

            $column = $altering === null ? null : self::columnDeclaredAt($tokens, $text, $index);

            if ($column !== null) {
                $found[] = [$altering, $column];
            }
        }

        return $found;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     * @return array{table: string, alters: bool}|null
     */
    private static function schemaTargetAt(array $tokens, array $text, int $index): ?array
    {
        $method = QueryStatements::calledMethodFor($tokens, $text, $index);
        $name = self::bareNameAt($text, $index);

        if ($name === null || ($method !== 'table' && $method !== 'create')) {
            return null;
        }

        if (! PatternScan::matches(self::SCHEMA_BUILDER, QueryStatements::receiverBefore($text, $index))) {
            return null;
        }

        return ['table' => $name, 'alters' => $method === 'table'];
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     */
    private static function columnDeclaredAt(array $tokens, array $text, int $index): ?string
    {
        $method = QueryStatements::calledMethodFor($tokens, $text, $index);
        $name = self::bareNameAt($text, $index);

        if ($name === null || $method === null || in_array($method, self::DECLARES_NO_COLUMN, true)) {
            return null;
        }

        return str_contains(QueryStatements::receiverBefore($text, $index), self::BLUEPRINT) ? $name : null;
    }

    /**
     * The table this statement was opened on, where that table carries columns
     * a migration added, plus the name a qualified column here has to use.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     * @param  array<string, array<string, string>>  $columns
     * @return array{table: string, scope: string}|null
     */
    private static function tableOpenedAt(array $tokens, array $text, int $index, array $columns): ?array
    {
        $method = QueryStatements::calledMethodFor($tokens, $text, $index);

        if ($method === null || ! PatternScan::matches(self::OPENS_A_BUILDER, $method)) {
            return null;
        }

        $literal = $text[$index];
        $inner = strlen($literal) > 2 && ($literal[0] === "'" || $literal[0] === '"') ? substr($literal, 1, -1) : '';
        $parts = PatternScan::split(self::ALIASED, $inner);
        $table = $parts[0] ?? '';

        return isset($columns[$table]) ? ['table' => $table, 'scope' => $parts[1] ?? $table] : null;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     * @param  array{int, int}  $bounds
     * @param  array{table: string, scope: string}  $opened
     * @param  array<string, array<string, string>>  $columns
     * @return list<array{table: string, column: string, method: string, qualified: bool}>
     */
    private static function predicatesIn(array $tokens, array $text, array $bounds, array $opened, array $columns): array
    {
        $found = [];

        for ($at = $bounds[0]; $at <= $bounds[1]; $at++) {
            $named = self::predicateColumnAt($tokens, $text, $at, $columns[$opened['table']]);

            if ($named === null) {
                continue;
            }

            $found[] = [
                'table' => $opened['table'],
                'column' => $named['column'],
                'method' => $named['method'],
                'qualified' => $named['qualified'],
            ];
        }

        return $found;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     * @param  array<string, string>  $carried
     * @return array{column: string, method: string, qualified: bool}|null
     */
    private static function predicateColumnAt(array $tokens, array $text, int $index, array $carried): ?array
    {
        $literal = $text[$index];
        $written = PatternScan::first("/^'((?:[a-z0-9_]+\\.)?[a-z0-9_]+)'$/", $literal)[1] ?? null;

        if (! is_string($written)) {
            return null;
        }

        $column = str_contains($written, '.') ? substr($written, (int) strrpos($written, '.') + 1) : $written;
        $method = QueryStatements::calledMethodFor($tokens, $text, $index);

        if (! isset($carried[$column]) || $method === null || ! PatternScan::matches(self::PREDICATE, $method)) {
            return null;
        }

        return ['column' => $column, 'method' => $method, 'qualified' => str_contains($written, '.')];
    }

    /** @param  list<string>  $text */
    private static function bareNameAt(array $text, int $index): ?string
    {
        $name = PatternScan::first(self::A_BARE_NAME, $text[$index])[1] ?? null;

        return is_string($name) ? $name : null;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return list<string>
     */
    private static function textOf(array $tokens): array
    {
        return array_map(static fn (array|string $token): string => is_array($token) ? $token[1] : $token, $tokens);
    }
}
