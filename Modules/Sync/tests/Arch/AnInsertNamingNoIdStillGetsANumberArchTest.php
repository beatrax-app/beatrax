<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Core\Public\Support\PatternScan;
use Modules\Sync\Internal\Config\MergeRulesRegistry;

uses(RefreshDatabase::class);

// A table given a derived id keeps an INTEGER primary key, which in SQLite is
// still an alias for the rowid: an insert naming no id gets max(id) + 1, just
// above the largest derived value, and no error. Both older guards read the
// literal AUTOINCREMENT out of the DDL, so dropping it to make room for the
// derived id also took the table out from under them — and three writers into
// known_senders left the id out for as long as nothing was watching.

// Every model whose $table names one of these, read out of the source rather
// than by resolving the class: a model is written well before it is loadable
// here, and the scan below only ever compares strings.
/** @return array<string, list<string>> table => the model class names writing it */
function modelClassesOverDerivedIdTables(): array
{
    $byTable = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php' || ! str_contains($path, '/Models/')) {
            continue;
        }

        $source = (string) file_get_contents($path);
        $class = basename($path, '.php');
        $declared = PatternScan::first("/protected \\\$table = '([a-z_]+)'/", $source);

        // Most models here declare no $table and take Laravel's own name for
        // it, so reading only the declaration would have left AnomalyAlert —
        // the other table this rule covers — with no model at all.
        $table = $declared[1] ?? Str::snake(Str::pluralStudly($class));

        $byTable[$table][] = $class;
    }

    return $byTable;
}

/** @return list<string> covered tables SQLite still numbers for a writer that names no id */
function coveredTablesSqliteStillNumbers(): array
{
    $tables = [];

    foreach (array_keys(app(MergeRulesRegistry::class)->rules()) as $table) {
        $create = DB::selectOne('select sql from sqlite_master where type = ? and name = ?', ['table', $table]);
        $sql = is_object($create) && is_string($create->sql ?? null) ? $create->sql : '';

        // AUTOINCREMENT means the table takes its ids from the sequence by
        // design, and two older guards account for those. What is left here is
        // the table that dropped it to install a derived id.
        if ($sql === '' || str_contains(strtolower($sql), 'autoincrement')) {
            continue;
        }

        $keys = array_values(array_filter(
            DB::select('select name, type, pk from pragma_table_info(?)', [$table]),
            static fn (object $column): bool => (int) $column->pk === 1,
        ));

        // One INTEGER column and nothing else is SQLite's rule for a rowid
        // alias. A varchar key, or a composite one, is never handed a number.
        if (count($keys) === 1 && strtolower((string) $keys[0]->type) === 'integer') {
            $tables[] = $table;
        }
    }

    sort($tables);

    return $tables;
}

/** @return list<string> */
function rowWritingCalls(): array
{
    return [
        'insert(', 'insertGetId(', 'insertOrIgnore(', 'insertUsing(', 'upsert(',
        'updateOrInsert(', 'create(', 'updateOrCreate(', 'firstOrCreate(', 'firstOrNew(',
    ];
}

// Enough of the statement to reach the verb written after the table or the
// model, and not enough to reach the next one. A whole file is too loose: a
// reader of one table sits in the same class as a writer of another.
function reachesARowWrite(string $source, string $needle): bool
{
    $offset = 0;

    while (($at = strpos($source, $needle, $offset)) !== false) {
        $window = substr($source, $at, 300);

        foreach (rowWritingCalls() as $verb) {
            if (str_contains($window, $verb)) {
                return true;
            }
        }

        $offset = $at + 1;
    }

    return false;
}

/**
 * @param  list<string>  $paths
 * @return list<string>
 */
function derivedIdWritesNamingNoId(array $paths): array
{
    $models = modelClassesOverDerivedIdTables();
    $offenders = [];

    foreach (coveredTablesSqliteStillNumbers() as $table) {
        $needles = ["table('".$table."')", "table: '".$table."'"];

        foreach ($models[$table] ?? [] as $class) {
            $needles[] = $class.'::';
            $needles[] = 'new '.$class;
        }

        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);

            $writes = false;
            foreach ($needles as $needle) {
                $writes = $writes || reachesARowWrite($source, $needle);
            }

            // Either answer counts. The rule is that the id is NAMED at the
            // write, not which of the two schemes names it: anomaly_alerts
            // mints because its identity tuple would have to fold a foreign
            // key each device numbers for itself.
            $names = str_contains($source, "DerivedRowId::for('".$table."'")
                || str_contains($source, 'DeviceMintedRowId::mint(');

            if ($writes && ! $names) {
                $offenders[] = str_replace(base_path().'/', '', $path).' writes '.$table;
            }
        }
    }

    sort($offenders);

    return $offenders;
}

// Walked rather than found through Finder, and named apart from every other
// walker: composer-require-checker reads Modules/*/tests as production code,
// and two globals of one name in one process is a fatal.
/** @return list<string> */
function everyFileThatCouldWriteARow(string $root, bool $productionOnly = true): array
{
    $paths = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }

        if ($productionOnly && PatternScan::matches('#/tests/#', $path)) {
            continue;
        }

        $paths[] = $path;
    }

    sort($paths);

    return $paths;
}

/** @return list<string> */
function productionFilesThatCouldWriteARow(): array
{
    return [
        ...everyFileThatCouldWriteARow(base_path('Modules')),
        ...everyFileThatCouldWriteARow(base_path('app')),
    ];
}

// Three ways to see nothing: no covered table left without AUTOINCREMENT, no
// model resolved for the one the defect was measured on, and a walk that found
// no files. All three read as a clean pass, so all three are asserted.
it('reads a derived-id table, its model and a tree of writers', function (): void {
    // The table the defect was measured on. It falling out of the set would
    // leave the rule walking nothing and reporting a clean pass.
    expect(in_array('known_senders', coveredTablesSqliteStillNumbers(), true))->toBeTrue();

    expect(modelClassesOverDerivedIdTables()['known_senders'] ?? [])->toContain('KnownSender')
        ->and(modelClassesOverDerivedIdTables()['anomaly_alerts'] ?? [])->toContain('AnomalyAlert');

    expect(count(productionFilesThatCouldWriteARow()))->toBeGreaterThan(500);
});

it('names the id on every production write into a derived-id table', function (): void {
    expect(derivedIdWritesNamingNoId(productionFilesThatCouldWriteARow()))->toBe([], implode("\n", [
        'These write a row into a table that dropped AUTOINCREMENT to take an id of its',
        'own, and leave the id out. SQLite keeps an INTEGER key as a rowid alias, so it',
        'answers with max(id) + 1 — a number that says only what this device happened to',
        'hold. The peer hands the same one to a different row and the arriving create is',
        'refused. Name the id at the write, with DerivedRowId::for() where both devices',
        'compute the same row and DeviceMintedRowId::mint() where they do not.',
    ]));
});

// The rule proved on a writer that does the wrong thing. It lives under tests/,
// which the production walk skips: a probe inside the scanned tree would make
// the rule pass by being fixed, and a rule nothing can fail is not a rule.
it('catches a writer that leaves the id out', function (): void {
    $probe = everyFileThatCouldWriteARow(
        base_path('Modules/Sync/tests/Fixtures/DerivedIdWriters'),
        productionOnly: false,
    );

    expect($probe)->toHaveCount(1)
        ->and(derivedIdWritesNamingNoId($probe))->toBe([
            'Modules/Sync/tests/Fixtures/DerivedIdWriters/AKnownSenderWrittenWithoutItsId.php writes known_senders',
        ])
        ->and(derivedIdWritesNamingNoId(productionFilesThatCouldWriteARow()))->not->toContain(
            'Modules/Sync/tests/Fixtures/DerivedIdWriters/AKnownSenderWrittenWithoutItsId.php writes known_senders',
        );
});
