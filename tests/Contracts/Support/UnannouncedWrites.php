<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;
use Modules\Sync\Internal\OpLog\OpLogBackfiller;
use ReflectionClass;

// The two spellings of a write that the column, delete and users guards cannot
// read. Each of those three roots its scan at a table literal — `table('goals')`
// — or at a model class used as a static query root, so a statement that names
// its table inside a SQL string and a write called on a hydrated row are both
// invisible to all three at once. `transactions.field_provenance` shipped as the
// first shape and travelled only inside a create, where it is always null.
final class UnannouncedWrites
{
    // Matches BoundaryArchTest's raw-statement pass deliberately: one spelling
    // of "this string is a write" is enough, and two that drift apart would let
    // a statement satisfy one rule while the other never saw it.
    public const string RAW_STATEMENT = '/\b(?:INSERT\s+(?:OR\s+[A-Z]+\s+)?INTO|REPLACE\s+INTO|UPDATE|DELETE\s+FROM'
        .'|TRUNCATE(?:\s+TABLE)?)\s+[`"\']?([a-z0-9_]+)[`"\']?/i';

    /**
     * Every table the merge registry declares, minus the ones the backfiller
     * keeps on the device. Read off both classes rather than restated, so a
     * table struck off either is struck off here.
     *
     * @return list<string>
     */
    public static function travellingTables(MergeRulesRegistry $registry): array
    {
        /** @var list<string> $deviceLocal */
        $deviceLocal = (new ReflectionClass(OpLogBackfiller::class))->getConstant('DEVICE_LOCAL_TABLES');

        $tables = [];

        foreach (array_keys($registry->rules()) as $table) {
            if (! in_array((string) $table, $deviceLocal, true)) {
                $tables[] = (string) $table;
            }
        }

        return $tables;
    }

    /**
     * The travelling tables this source writes to in a SQL string.
     *
     * @param  list<string>  $tables
     * @return list<string>
     */
    public static function rawStatementTargets(string $source, array $tables): array
    {
        $targets = [];

        foreach (PatternScan::all(self::RAW_STATEMENT, $source)[1] ?? [] as $named) {
            $named = strtolower($named);

            if (in_array($named, $tables, true) && ! in_array($named, $targets, true)) {
                $targets[] = $named;
            }
        }

        sort($targets);

        return $targets;
    }

    // `$run->update(['status' => …])` on a row already in hand. The receiver is
    // a variable, so the table is named nowhere in the statement — the model
    // class being used as a query root in the same file is what stands in for
    // it, the same stand-in the column guard's save() shape uses.
    public static function instanceUpdatesColumn(string $model, string $column, string $source): bool
    {
        return self::queriesModel($model, $source)
            && PatternScan::matches(
                '/\$[A-Za-z_][A-Za-z0-9_]*\s*->\s*update\(\s*\[[^;]{0,700}?\''.$column.'\'\s*=>/s',
                $source,
            );
    }

    // The delete half of the same shape. Rooted at the model being queried,
    // because `$row->delete()` on its own says nothing about which table.
    public static function instanceDeletesRow(string $model, string $source): bool
    {
        return self::queriesModel($model, $source)
            && PatternScan::matches('/\$[A-Za-z_][A-Za-z0-9_]*\s*->\s*(?:delete|forceDelete)\(\)/', $source);
    }

    // A type hint is not a write: every action in this tree names User in its
    // signature, and matching the bare class reported each of them for whatever
    // unrelated row it happened to delete.
    private static function queriesModel(string $model, string $source): bool
    {
        return PatternScan::matches(
            '/\b'.$model.'::(?:query|find|findOrFail|firstWhere|where)\b|new\s+'.$model.'\b/',
            $source,
        );
    }
}
