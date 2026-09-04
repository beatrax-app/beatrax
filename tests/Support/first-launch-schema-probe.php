<?php

declare(strict_types=1);

/**
 * @link ../../.docs/features/mobile/architecture.md#the-migrations-only-a-phone-ever-runs
 */

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Foundation\Application;
use Modules\Mobile\Internal\Boot\MobileFirstLaunchBootstrap;
use Modules\Mobile\Internal\Boot\SchemaCompletionMarker;

// Builds one schema in its own process and prints it as JSON. A process of its
// own because the phone migrates onto the DEFAULT connection with a DATABASE
// cache store, and a test that swaps the default connection inside a phpunit
// worker leaves RefreshDatabase holding one that no longer exists.
//
// FIRST_LAUNCH_LOAD_SCHEMA is the ONLY difference between the phone's run and
// the desktop's: one loads database/schema/sqlite-schema.sql first, the other
// has no `sqlite3` binary to load it with and migrates from the first file.
// Everything downstream of that flag is shared, so a difference in the two
// schemas can only have come from the migrations in between.

$root = (string) getenv('FIRST_LAUNCH_APP_ROOT');
$loadSchema = getenv('FIRST_LAUNCH_LOAD_SCHEMA') === '1';

require $root.'/vendor/autoload.php';

/** @var Application $app */
$app = require $root.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// What mobile-app/bootstrap/app.php sets on the device before the first
// migration runs. Left at the suite's `array` default a cache store reaches no
// table at all, which is what hid a migration writing to one that did not exist.
$config = $app->make('config');
$config->set('cache.default', 'database');
$config->set('session.driver', 'database');

/** @var DatabaseManager $db */
$db = $app->make(DatabaseManager::class);
$connection = $db->connection();

$report = ['error' => null, 'schemaLoaded' => $loadSchema];

// PDO rather than the sqlite3 binary Laravel shells out to for a file database.
// The statements are the same file's; this keeps the reference reproducible on
// a runner that ships the PHP extension without the command-line tool - which
// is also the difference the phone cannot bridge.
if ($loadSchema) {
    $connection->getPdo()->exec((string) file_get_contents($root.'/database/schema/sqlite-schema.sql'));
}

$bootstrap = $app->make(MobileFirstLaunchBootstrap::class);

// Whatever the dump already recorded as run. Empty for the phone, and on the
// desktop this list IS the stretch of migrations no desktop and no CI job has
// ever executed.
$report['preloaded'] = $loadSchema
    ? $app->make('migrator')->getRepository()->getRan()
    : [];

try {
    $bootstrap->runPendingMigrations();
} catch (Throwable $e) {
    $report['error'] = ['class' => $e::class, 'message' => $e->getMessage()];
}

$report['markerRaised'] = SchemaCompletionMarker::isRaised();
$report['database'] = $connection->getDatabaseName();

$objects = $connection->select(
    "select type, name from sqlite_master where name not like 'sqlite_%' order by type, name",
);
$report['tables'] = array_values(array_map(
    static fn (object $row): string => (string) $row->name,
    array_filter($objects, static fn (object $row): bool => $row->type === 'table'),
));

$columns = [];
$indexes = [];
$foreignKeys = [];

foreach ($report['tables'] as $table) {
    $definitions = array_map(
        static fn (object $column): string => $column->name.' '.strtolower((string) $column->type)
            .' notnull='.$column->notnull
            .' pk='.$column->pk
            .' default='.var_export($column->dflt_value, true),
        $connection->select('pragma table_info("'.$table.'")'),
    );
    sort($definitions);
    $columns[$table] = $definitions;

    $onTable = [];
    foreach ($connection->select('pragma index_list("'.$table.'")') as $index) {
        $indexed = array_map(
            static fn (object $column): string => (string) $column->name,
            $connection->select('pragma index_info("'.$index->name.'")'),
        );
        $onTable[] = ((int) $index->unique === 1 ? 'unique(' : 'index(').implode(',', $indexed).')';
    }
    sort($onTable);
    $indexes[$table] = $onTable;

    $references = array_map(
        static fn (object $key): string => $key->from.' -> '.$key->table.'.'.$key->to.' on delete '.$key->on_delete,
        $connection->select('pragma foreign_key_list("'.$table.'")'),
    );
    sort($references);
    $foreignKeys[$table] = $references;
}

$report['columns'] = $columns;
$report['indexes'] = $indexes;
$report['foreignKeys'] = $foreignKeys;
$report['pending'] = $bootstrap->hasPendingMigrations();

$applied = $connection->select('select migration from migrations order by id');
$report['applied'] = array_map(
    static fn (object $row): string => (string) $row->migration,
    $applied,
);

echo json_encode($report, JSON_UNESCAPED_SLASHES), PHP_EOL;
