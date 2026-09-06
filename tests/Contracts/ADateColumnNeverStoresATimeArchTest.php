<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Model;

// SQLite compares dates as strings, so '2026-09-16 00:00:00' <= '2026-09-16'
// is FALSE and the boundary day drops out of every range bounded that way. A
// DATE column has one storable shape, and the only thing between a writer and
// the wrong one is the model's cast.
//
// Neither Eloquent date cast gives that: immutable_date writes nineteen
// characters whatever it is handed, and immutable_date:Y-m-d normalises a
// string but leaves a DateTimeInterface for the grammar to bind as
// Y-m-d H:i:s. Two columns carried that pin and thirteen went unchecked, so
// this walks the migrated schema instead.
// @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-date-column-carrying-a-time

/**
 * @return array<string, string> "table.column" => the reason no model cast covers it
 */
function dateColumnsWithNoModelCast(): array
{
    return [
        // Reference data with no Eloquent model at all: written by
        // FetchFxRatesJob and SeedBundledExchangeRates through the query
        // builder, both from a Y-m-d string.
        'exchange_rates.rate_date' => 'no model; query-builder writers pass Y-m-d',

        // Per-run import staging, truncated between runs and never synced.
        // The importer writes these through the query builder.
        'migration_staging_budget_assignments.period_start' => 'no model; per-run import staging',
        'migration_staging_goals.target_date' => 'no model; per-run import staging',

        // Deliberate: the model declares period_start a string and every
        // writer reaches it through ->start->toDateString(). A date cast here
        // would hand a CarbonImmutable to code that compares period keys.
        'envelope_assignments.period_start' => 'declared string on EnvelopeAssignment; writers pass toDateString()',
        'envelope_moves.period_start' => 'no model; written beside envelope_assignments from the same period key',
    ];
}

/** @return array<string, list<string>> table => its DATE columns, from the migrated schema */
function dateColumnsInSchema(): array
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);
    $schema = $db->connection()->getSchemaBuilder();

    $found = [];
    foreach ($schema->getTables() as $table) {
        $name = is_array($table) && isset($table['name']) && is_string($table['name']) ? $table['name'] : null;
        if ($name === null || str_starts_with($name, 'sqlite_')) {
            continue;
        }

        foreach ($schema->getColumns($name) as $column) {
            if (($column['type_name'] ?? null) === 'date' && is_string($column['name'])) {
                $found[$name][] = $column['name'];
            }
        }
    }

    ksort($found);

    return $found;
}

/** @return array<string, class-string<Model>> table => the model that owns it */
function dateColumnModelsByTable(): array
{
    $models = [];

    /** @var Iterator<SplFileInfo> $files */
    $files = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '#/Models/[A-Za-z]+\.php$#',
    );

    foreach ($files as $file) {
        $path = $file->getPathname();
        if (str_contains($path, '/tests/')) {
            continue;
        }

        $source = (string) file_get_contents($path);
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $namespace) !== 1) {
            continue;
        }

        /** @var class-string $class */
        $class = trim($namespace[1]).'\\'.$file->getBasename('.php');
        if (! class_exists($class)) {
            continue;
        }

        $reflection = new ReflectionClass($class);
        if (! $reflection->isInstantiable() || ! $reflection->isSubclassOf(Model::class)) {
            continue;
        }

        /** @var Model $instance */
        $instance = $reflection->newInstance();
        $models[$instance->getTable()] = $class;
    }

    return $models;
}

it('gives every DATE column in the schema a model cast, or a stated reason it has none', function (): void {
    $columns = dateColumnsInSchema();
    expect($columns)->not->toBeEmpty(
        'The migrated schema reports no DATE column at all, so this rule read a database nobody migrated.',
    );

    $models = dateColumnModelsByTable();
    $exempt = dateColumnsWithNoModelCast();
    $uncovered = [];

    foreach ($columns as $table => $names) {
        foreach ($names as $column) {
            if (isset($exempt[$table.'.'.$column])) {
                continue;
            }

            $model = $models[$table] ?? null;
            if ($model === null || ! (new $model)->hasCast($column)) {
                $uncovered[] = $table.'.'.$column;
            }
        }
    }

    expect($uncovered)->toBe(
        [],
        "A DATE column with no cast takes whatever shape its writer happened to hold.\n".
        "Cast it with Modules\\Core\\Public\\Casts\\DateOnlyCast, or add it to\n".
        'dateColumnsWithNoModelCast() with the reason it needs none. Uncovered:',
    );
});

it('never lets a DATE column cast store more than the ten characters of a day', function (): void {
    $models = dateColumnModelsByTable();
    $exempt = dateColumnsWithNoModelCast();
    $wrong = [];
    $exercised = 0;

    // Every shape a writer can hold a day in. The string with a time is what a
    // reader of a legacy nineteen-character row hands straight back.
    $inputs = [
        'CarbonImmutable' => CarbonImmutable::parse('2026-09-16 13:45:07'),
        'DateTimeImmutable' => new DateTimeImmutable('2026-09-16 13:45:07'),
        'DateTime' => new DateTime('2026-09-16 13:45:07'),
        'Y-m-d string' => '2026-09-16',
        'Y-m-d H:i:s string' => '2026-09-16 13:45:07',
    ];

    foreach (dateColumnsInSchema() as $table => $names) {
        foreach ($names as $column) {
            $model = $models[$table] ?? null;
            if (isset($exempt[$table.'.'.$column]) || $model === null || ! (new $model)->hasCast($column)) {
                continue;
            }

            $exercised++;

            foreach ($inputs as $shape => $input) {
                $instance = new $model;
                $instance->setAttribute($column, $input);
                $stored = $instance->getAttributes()[$column] ?? null;

                if ($stored !== '2026-09-16') {
                    $wrong[] = $table.'.'.$column.' <- '.$shape.' => '.(
                        $stored instanceof DateTimeInterface
                            ? get_debug_type($stored).'('.$stored->format('Y-m-d H:i:s').')'
                            : var_export($stored, true)
                    );
                }

                // Eloquent serialises a date-returning class cast through
                // serializeDate(), which rewrites the day into UTC — east of it
                // a DATE column reported the day before in every array and JSON
                // form the model has.
                $serialised = $instance->toArray()[$column] ?? null;
                if ($serialised !== '2026-09-16') {
                    $wrong[] = $table.'.'.$column.' <- '.$shape.' => toArray '.var_export($serialised, true);
                }
            }
        }
    }

    // Ten DATE columns carry DateOnlyCast today. A run that exercised none of
    // them stores nothing wrongly, which is the answer a correct tree gives.
    expect($exercised)->toBeGreaterThan(
        5,
        'The run put '.$exercised.' cast DATE columns through the five shapes a writer can hold a day in, so the '
        .'model reader stopped rather than the tree getting smaller.',
    );

    expect($wrong)->toBe(
        [],
        "Stored as anything but Y-m-d, a DATE column loses its own boundary day to\n".
        "every range that compares against a bare date. Wrong shape:\n  ".implode("\n  ", $wrong),
    );
});

// The other way this list stops describing the tree: a column that has since
// been given a cast is a column the exemption waves past the rule for no
// reason, and the reason it carries has stopped being the one that applies.
it('carries no exemption for a DATE column a model now casts', function (): void {
    $models = dateColumnModelsByTable();
    $covered = [];

    foreach (dateColumnsWithNoModelCast() as $column => $reason) {
        [$table, $name] = explode('.', $column, 2);
        $model = $models[$table] ?? null;

        if ($model !== null && (new $model)->hasCast($name)) {
            $covered[] = $column.' is exempt as "'.$reason.'", and '.$model.' casts it now';
        }
    }

    expect($covered)->toBe([], implode("\n  ", [
        'An exemption that excuses nothing reads as a decision somebody made about this column, and it hides the',
        'cast from the shape check above. Delete the entry:',
        ...$covered,
    ]));
});

it('carries no exemption for a DATE column the schema no longer has', function (): void {
    $present = [];
    foreach (dateColumnsInSchema() as $table => $names) {
        foreach ($names as $column) {
            $present[] = $table.'.'.$column;
        }
    }

    $gone = array_values(array_diff(array_keys(dateColumnsWithNoModelCast()), $present));

    expect($gone)->toBe([], 'Remove the exemption for a DATE column that no longer exists: '.implode(', ', $gone));
});
