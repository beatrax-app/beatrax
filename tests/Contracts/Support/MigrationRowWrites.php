<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Public\Support\KeylessTombstone;

// Every announce guard in this tree skips migrations by construction: the
// production scope declines `/Database/Migrations/` and the runtime-domain
// scope does too, both on the reading that a migration declares schema rather
// than writing a reader's rows. Thirty-one of them write row data into a table
// the merge registry declares, and no rule has ever asked whether the peer was
// told.
final class MigrationRowWrites
{
    // Read off the migration's own anonymous class, as source text rather than
    // by reflection: loading thirty-one anonymous classes to read one constant
    // runs their imports against whatever the test container happens to hold.
    public const string DECLINATION = 'DOES_NOT_ANNOUNCE';

    // The builder half. `truncate` and the two `insertUsing` spellings are in
    // because a row write is a row write whichever verb reaches for it, not
    // because a migration here uses them.
    private const string WRITE_CALL = '/->\s*(?:insert|insertGetId|insertOrIgnore|insertUsing|update|updateOrInsert'
        .'|upsert|delete|truncate)\s*\(/';

    /** @var list<array{path: string, tables: list<string>, announces: bool, ground: ?string, reason: ?string}>|null */
    private static ?array $readings = null;

    /** @var list<string>|null */
    private static ?array $tables = null;

    /**
     * Off the registry rather than restated, so a table struck off there is
     * struck off here. The backfiller's device-local three stay in: a migration
     * writing one of them is still in scope and declines on that ground, which
     * is a claim a reader can check rather than a silence.
     *
     * @return list<string>
     */
    public static function tables(): array
    {
        return self::$tables ??= array_map(
            static fn (string|int $table): string => (string) $table,
            array_keys((new MergeRulesRegistry)->rules()),
        );
    }

    /**
     * @return list<string> absolute paths
     */
    public static function files(): array
    {
        return array_values(array_filter(
            RepoTree::files(RepoTree::EVERY_PHP_FILE),
            static fn (string $path): bool => str_contains($path, '/Database/Migrations/')
                || str_contains($path, '/database/migrations/'),
        ));
    }

    /**
     * Every migration in the tree, read once.
     *
     * @return list<array{path: string, tables: list<string>, announces: bool, ground: ?string, reason: ?string}>
     */
    public static function all(): array
    {
        if (self::$readings !== null) {
            return self::$readings;
        }

        $tables = self::tables();
        $root = RepoTree::root().'/';
        $readings = [];

        foreach (self::files() as $path) {
            $readings[] = self::in(
                str_replace($root, '', $path),
                (string) file_get_contents($path),
                $tables,
            );
        }

        return self::$readings = $readings;
    }

    /**
     * @return list<array{path: string, tables: list<string>, announces: bool, ground: ?string, reason: ?string}>
     */
    public static function writingADeclaredTable(): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (array $reading): bool => $reading['tables'] !== [],
        ));
    }

    /**
     * @param  list<string>  $tables
     * @return array{path: string, tables: list<string>, announces: bool, ground: ?string, reason: ?string}
     */
    public static function in(string $path, string $source, array $tables): array
    {
        /** @var list<array{0:int,1:string,2:int}|string> $tokens */
        $tokens = token_get_all($source);
        $text = array_map(static fn (array|string $token): string => is_array($token) ? $token[1] : $token, $tokens);

        [$ground, $reason] = self::declinationIn($tokens, $text);

        return [
            'path' => $path,
            'tables' => self::writtenTables($tokens, $text, $tables),
            'announces' => str_contains($source, self::announcement()),
            'ground' => $ground,
            'reason' => $reason,
        ];
    }

    // The seam a migration announces through, spelled off the class so a rename
    // fails here rather than reading every migration as silent.
    public static function announcement(): string
    {
        $parts = explode('\\', KeylessTombstone::class);

        return end($parts).'::announce(';
    }

    /**
     * Two spellings of the same write. The builder chain is read through the
     * shared statement walk, because a filter can stand either side of the
     * table name; the raw statement is read over string literals only, so a
     * comment saying "a raw UPDATE" is not a write.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     * @param  list<string>  $tables
     * @return list<string>
     */
    private static function writtenTables(array $tokens, array $text, array $tables): array
    {
        $written = [];

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }

            $named = trim($token[1], '\'"');

            if (! in_array($named, $tables, true) || QueryStatements::calledMethodFor($tokens, $text, $index) !== 'table') {
                continue;
            }

            if (PatternScan::matches(self::WRITE_CALL, QueryStatements::around($text, $index))) {
                $written[$named] = true;
            }
        }

        foreach (UnannouncedWrites::rawStatementTargets(self::literalsIn($tokens), $tables) as $named) {
            $written[$named] = true;
        }

        $named = array_keys($written);
        sort($named);

        return $named;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     */
    private static function literalsIn(array $tokens): string
    {
        $literals = [];

        foreach ($tokens as $token) {
            if (is_array($token) && in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                $literals[] = $token[1];
            }
        }

        return implode("\n", $literals);
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     * @return array{0: ?string, 1: ?string}
     */
    private static function declinationIn(array $tokens, array $text): array
    {
        $stated = self::statedDeclination($tokens, $text);

        if ($stated === null) {
            return [null, null];
        }

        $parts = explode(':', $stated, 2);

        return count($parts) === 2 ? [trim($parts[0]), trim($parts[1])] : [trim($stated), ''];
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $text
     */
    private static function statedDeclination(array $tokens, array $text): ?string
    {
        $count = count($tokens);

        foreach ($tokens as $index => $token) {
            if (! is_array($token) || $token[0] !== T_STRING || $token[1] !== self::DECLINATION) {
                continue;
            }

            for ($at = $index + 1; $at < $count; $at++) {
                if (trim($text[$at]) === '' || $text[$at] === '=') {
                    continue;
                }

                $value = $tokens[$at];

                return is_array($value) && $value[0] === T_CONSTANT_ENCAPSED_STRING
                    ? trim($value[1], '\'"')
                    : null;
            }
        }

        return null;
    }
}
