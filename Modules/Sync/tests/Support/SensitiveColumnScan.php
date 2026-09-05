<?php

declare(strict_types=1);

namespace Modules\Sync\Tests\Support;

use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Crypto\SensitiveFieldRegistry;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * @phpstan-type Hit array{
 *     path: string,
 *     line: int,
 *     column: string,
 *     kind: string,
 *     table: string|null,
 *     text: string,
 *     cleared: string|null,
 * }
 *
 * @link ../../../../.docs/features/sync/sensitive-columns-at-rest.md#how-the-predicate-guard-decides-what-it-is-looking-at
 */
final class SensitiveColumnScan
{
    // Any method whose name CONTAINS a write verb, not a closed list of
    // spellings. `->insert(` could not see `insertChunked()`, `firstOrCreate()`
    // or `findOrCreate()`, and the next helper to be named that way is not
    // knowable — so the verb is matched as a word part, in either case.
    private const string WRITE_VERBS = '\\w*(?i:insert|update|upsert|create|fill|save)\\w*';

    // A write whose column value is produced by a same-file helper is coded by
    // that helper, so the callee's body is read once. One hop, because a chain
    // long enough to need two is a chain a reader cannot check either.
    private const string CODEC_CALLS = '/(?:encryptValue|encryptAttrs)\s*\(/';

    /**
     * @return list<string> every hit KIND this scanner can report
     */
    public static function kinds(): array
    {
        return ['where', 'orderBy', 'groupBy', 'join', 'whereRaw', 'having', 'json_decode', 'write'];
    }

    /**
     * @return list<string> bare column names derived from the registry's {table}.{column} pairs
     */
    public static function bareColumns(string ...$sealedPairs): array
    {
        $pairs = $sealedPairs === [] ? SensitiveFieldRegistry::columns() : $sealedPairs;

        $bare = [];
        foreach ($pairs as $pair) {
            [, $column] = explode('.', $pair, 2);
            $bare[$column] = true;
        }

        return array_keys($bare);
    }

    /**
     * Eloquent's own answer for every model in the tree, asked of the class
     * rather than guessed from its name: `Counterparty` is `counterparties`,
     * and a pluraliser that got that wrong would resolve a sealed call to a
     * table nothing seals and clear it.
     *
     * @return array<string, string> model short name => table name
     */
    public static function modelTables(string $repositoryRoot): array
    {
        $map = [];

        foreach (glob($repositoryRoot.'Modules/*/Models/*.php') ?: [] as $file) {
            $source = (string) file_get_contents($file);
            $namespace = PatternScan::first('/^namespace\s+([^;]+);/m', $source);
            if ($namespace === []) {
                continue;
            }

            $class = trim($namespace[1]).'\\'.basename($file, '.php');
            if (! class_exists($class) || ! is_subclass_of($class, Model::class)) {
                continue;
            }

            /** @var Model $instance */
            $instance = new $class;
            $map[basename($file, '.php')] = $instance->getTable();
        }

        return $map;
    }

    /**
     * @return array<string, string> absolute path => repo-relative path, for production PHP only
     */
    public static function productionFiles(string $repositoryRoot): array
    {
        $files = [];

        foreach (['Modules', 'app'] as $root) {
            $dir = $repositoryRoot.$root;
            if (! is_dir($dir)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            ) as $file) {
                $path = $file->getPathname();
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                if (str_contains($path, '/tests/') || str_contains($path, '/Database/') || str_contains($path, '/Resources/')) {
                    continue;
                }
                $files[$path] = str_replace($repositoryRoot, '', $path);
            }
        }

        ksort($files);

        return $files;
    }

    /**
     * Every place $contents names a sealed column, with what the call is doing
     * to it and — where the chain says so — which table it is doing it to.
     *
     * @param  array<string, string>  $modelTables
     * @param  list<string>  $sealedPairs
     * @return list<array<string, mixed>>
     */
    public static function hits(string $relative, string $contents, array $modelTables, array $sealedPairs): array
    {
        $hits = [];

        foreach (self::bareColumns(...$sealedPairs) as $column) {
            $quoted = preg_quote($column, '/');

            foreach (self::predicatePatterns($quoted) as $kind => $pattern) {
                foreach (PatternScan::allWithOffsets($pattern, $contents)[0] as $match) {
                    $hits[] = self::hit($relative, $contents, $match[1], $column, $kind, $match[0], $modelTables, $sealedPairs, null);
                }
            }

            foreach (self::writeHits($relative, $contents, $column, $quoted, $modelTables, $sealedPairs) as $hit) {
                $hits[] = $hit;
            }
        }

        usort($hits, static fn (array $a, array $b): int => [$a['line'], $a['column'], $a['kind']] <=> [$b['line'], $b['column'], $b['kind']]);

        return $hits;
    }

    /**
     * @param  list<array<string, mixed>>  $hits
     * @return list<array<string, mixed>>
     */
    public static function offenders(array $hits): array
    {
        return array_values(array_filter($hits, static fn (array $hit): bool => $hit['cleared'] === null));
    }

    /**
     * The unit an exemption is granted in. Not the file: a file earns nothing
     * by encrypting one of its columns correctly, and the signature says which
     * column, in which shape of use, is the one being argued about.
     *
     * @param  array<string, mixed>  $hit
     */
    public static function signature(array $hit): string
    {
        return $hit['path'].'::'.$hit['column'].'::'.$hit['kind'];
    }

    /**
     * @param  array<string, mixed>  $hit
     */
    public static function describe(array $hit): string
    {
        return self::signature($hit)
            .'  line '.$hit['line']
            .'  table '.($hit['table'] ?? 'UNRESOLVED')
            .'  '.$hit['text'];
    }

    /**
     * An exemption's reason has to name the {table}.{column} it rests on, or
     * the claim it makes is one nothing can check. This is that name, pulled
     * back out of the prose so the registry can be asked about it.
     *
     * @return list<string>
     */
    public static function citedColumns(string $reason): array
    {
        return array_values(array_unique(PatternScan::all('/\\b[a-z][a-z0-9_]*\\.[a-z][a-z0-9_]*\\b/', $reason)[0]));
    }

    /**
     * @return array<string, string> kind => pattern
     */
    private static function predicatePatterns(string $quoted): array
    {
        return [
            'where' => '/->(?:or)?[wW]here(?:In|Not|NotIn|Between|Like|NotLike)?\(\s*[\'"]'.$quoted.'[\'"]/',
            'orderBy' => '/->orderBy(?:Desc)?\(\s*[\'"]'.$quoted.'[\'"]/',
            'groupBy' => '/->groupBy\(\s*[\'"]'.$quoted.'[\'"]/',
            'join' => '/->(?:on|(?:left|right|inner|cross)?[jJ]oin)\([^)]*[\'"][a-z_]*\.?'.$quoted.'[\'"]/',
            'whereRaw' => '/(?:where|having|orderBy)Raw\([^)]*'.$quoted.'/i',
            'having' => '/->having\(\s*[\'"]'.$quoted.'[\'"]/',
            // The column read out of a row, not a local whose name happens to
            // start with it: `json_decode($paramsJson)` is a decrypted string
            // already, and matching it reported two files that were correct.
            'json_decode' => '/json_decode\(\s*\$\w+(?:->|\[[\'"])'.$quoted.'\b/',
        ];
    }

    /**
     * @param  array<string, string>  $modelTables
     * @param  list<string>  $sealedPairs
     * @return list<array<string, mixed>>
     */
    private static function writeHits(string $relative, string $contents, string $column, string $quoted, array $modelTables, array $sealedPairs): array
    {
        $hits = [];

        foreach (PatternScan::setsWithOffsets('/->('.self::WRITE_VERBS.')\s*\(/', $contents) as $call) {
            $open = $call[0][1] + strlen($call[0][0]) - 1;
            $arguments = self::balanced($contents, $open);

            foreach (PatternScan::allWithOffsets('/[\'"]'.$quoted.'[\'"]\s*=>/', $arguments)[0] as $key) {
                $value = self::valueExpression($arguments, $key[1] + strlen($key[0]));
                $cleared = self::codedBy($contents, $arguments, $value, $column, $sealedPairs);

                $hits[] = self::hit(
                    $relative,
                    $contents,
                    $call[0][1],
                    $column,
                    'write',
                    '->'.$call[1][0].'([… '.trim($key[0]).' '.self::shorten($value).' …])',
                    $modelTables,
                    $sealedPairs,
                    $cleared,
                );
            }
        }

        return $hits;
    }

    /**
     * @param  array<string, string>  $modelTables
     * @param  list<string>  $sealedPairs
     * @return array<string, mixed>
     */
    private static function hit(
        string $relative,
        string $contents,
        int $offset,
        string $column,
        string $kind,
        string $text,
        array $modelTables,
        array $sealedPairs,
        ?string $cleared,
    ): array {
        $table = self::tableOf(self::statementBefore($contents, $offset), $modelTables);

        // The only exemption nobody can acquire by accident: the call names a
        // table, and the registry does not seal that table's column of this
        // name. `accounts.iban` is not `counterparties.iban`, and the call says
        // which one it means. Promote the column and all of it goes red.
        if ($cleared === null && $table !== null && ! in_array($table.'.'.$column, $sealedPairs, true)) {
            $cleared = 'names '.$table.'.'.$column.', which the registry does not seal';
        }

        return [
            'path' => $relative,
            'line' => substr_count(substr($contents, 0, $offset), "\n") + 1,
            'column' => $column,
            'kind' => $kind,
            'table' => $table,
            'text' => self::shorten($text),
            'cleared' => $cleared,
        ];
    }

    /**
     * @param  list<string>  $sealedPairs
     */
    private static function codedBy(string $contents, string $arguments, string $value, string $column, array $sealedPairs): ?string
    {
        if (PatternScan::matches(self::CODEC_CALLS, $value)) {
            return 'the value routes through the codec';
        }

        foreach (self::sealedTablesFor($column, $sealedPairs) as $table) {
            if (PatternScan::matches('/encryptAttrs\(\s*[\'"]'.preg_quote($table, '/').'[\'"]/', $arguments)) {
                return 'the array is sealed by encryptAttrs('.$table.')';
            }
        }

        $callee = PatternScan::first('/(?:\$this->|self::|static::)(\w+)\s*\(/', $value);
        if ($callee !== [] && PatternScan::matches(self::CODEC_CALLS, self::methodBody($contents, $callee[1]))) {
            return 'the value comes from '.$callee[1].'(), which seals it';
        }

        return null;
    }

    /**
     * @param  list<string>  $sealedPairs
     * @return list<string>
     */
    private static function sealedTablesFor(string $column, array $sealedPairs): array
    {
        $tables = [];

        foreach ($sealedPairs as $pair) {
            [$table, $sealed] = explode('.', $pair, 2);
            if ($sealed === $column) {
                $tables[] = $table;
            }
        }

        return $tables;
    }

    // Pint holds every method body to one closing brace at four spaces, so the
    // body ends where that line does. A bound matters here: an unbounded read
    // would find a codec call further down the file and clear a write that has
    // none of its own.
    private static function methodBody(string $contents, string $name): string
    {
        $declaration = PatternScan::firstWithOffsets('/function\s+'.preg_quote($name, '/').'\s*\(/', $contents);
        if ($declaration === []) {
            return '';
        }

        $start = $declaration[0][1];
        $end = strpos($contents, "\n    }", $start);

        return $end === false ? substr($contents, $start) : substr($contents, $start, $end - $start);
    }

    // The balanced argument text, not a fixed character budget. The previous
    // reading stopped at 600 characters, so a long enough insert() hid its own
    // last columns from the scan and reported nothing.
    private static function balanced(string $contents, int $open): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($contents);

        for ($i = $open; $i < $length; $i++) {
            $char = $contents[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;

                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }
            if ($char === ')' || $char === ']') {
                $depth--;
                if ($depth === 0) {
                    return substr($contents, $open + 1, $i - $open - 1);
                }
            }
        }

        return substr($contents, $open + 1);
    }

    private static function valueExpression(string $arguments, int $from): string
    {
        $depth = 0;
        $quote = null;
        $length = strlen($arguments);

        for ($i = $from; $i < $length; $i++) {
            $char = $arguments[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;

                    continue;
                }
                if ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;

                continue;
            }
            if ($char === '(' || $char === '[') {
                $depth++;

                continue;
            }
            if ($char === ')' || $char === ']') {
                if ($depth === 0) {
                    return trim(substr($arguments, $from, $i - $from));
                }
                $depth--;

                continue;
            }
            if ($char === ',' && $depth === 0) {
                return trim(substr($arguments, $from, $i - $from));
            }
        }

        return trim(substr($arguments, $from));
    }

    private static function statementBefore(string $contents, int $offset): string
    {
        $start = 0;

        foreach ([';', '{', '}'] as $terminator) {
            $found = strrpos(substr($contents, 0, $offset), $terminator);
            if ($found !== false && $found + 1 > $start) {
                $start = $found + 1;
            }
        }

        return substr($contents, $start, $offset - $start);
    }

    /**
     * @param  array<string, string>  $modelTables
     */
    private static function tableOf(string $statement, array $modelTables): ?string
    {
        $tables = PatternScan::all('/(?:->|::)(?:table|from)\(\s*[\'"]([a-z0-9_]+)[\'"]/', $statement)[1];
        if ($tables !== []) {
            return (string) end($tables);
        }

        $models = PatternScan::all('/\b([A-Z][A-Za-z0-9_]*)::(?:query|where|find|first|insert|create|updateOrCreate|firstOrCreate)\b/', $statement)[1];
        if ($models === []) {
            return null;
        }

        return $modelTables[(string) end($models)] ?? null;
    }

    private static function shorten(string $text): string
    {
        $flat = PatternScan::replace('/\s+/', ' ', trim($text));

        return mb_strlen($flat) > 110 ? mb_substr($flat, 0, 107).'…' : $flat;
    }
}
