<?php

declare(strict_types=1);

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\Repository as CacheRepositoryImpl;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Modules\Core\Internal\Listeners\ForgetNavCountsOnWrite;
use Modules\Core\Internal\Support\MigrationWindow;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\NavCountsService;

// Mobile has no `sqlite3` binary, so MigrateCommand's schema-dump shortcut is
// unavailable to it: MobileFirstLaunchBootstrap drives the Migrator directly
// and every migration runs from the first. Desktop loads
// database/schema/sqlite-schema.sql and starts after 2026_06_13, so the whole
// stretch below is one only a phone ever executes.
//
// 2026_05_15_010002 adds a foreign key; SQLite has no ADD CONSTRAINT, so
// Laravel rebuilds the table and the copy it emits reads as
// `insert into "__temp__transactions" ... from "transactions"`.
// ForgetNavCountsOnWrite took that for a user write and bumped the nav-count
// generation into `cache` — a table 2026_05_21_001844 had not created yet. The
// run died on migration 19 of 190, the shell caught it "non-fatally", and the
// app opened on fifteen tables of a hundred and two.
//
// Driven straight at the listener rather than through a real migration run:
// both phpunit suites set CACHE_STORE=array, which cannot reach a table at
// all, and a test that swapped the default connection to get a database store
// left RefreshDatabase holding a connection that no longer existed — every
// later test in that worker then failed in its own setUp. A cache store with
// no table behind it is the same fault the phone hit, and it costs nothing.
function listenerOverACachelessStore(MigrationWindow $window): ForgetNavCountsOnWrite
{
    $file = sys_get_temp_dir().'/cacheless-'.bin2hex(random_bytes(5)).'.sqlite';
    touch($file);

    Config::set('database.connections.cacheless', [
        'driver' => 'sqlite',
        'database' => $file,
        'prefix' => '',
        'foreign_key_constraints' => false,
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Never made the default, and never handed to RefreshDatabase: this
    // connection exists only for the store below.
    $store = new DatabaseStore($db->connection('cacheless'), 'cache', 'beatrax-cache-');

    return new ForgetNavCountsOnWrite(
        new NavCountsService($db, new CacheRepositoryImpl($store), app(Clock::class)),
        $window,
    );
}

function theTableRebuildStatement(): QueryExecuted
{
    // Verbatim in shape: SQLite rebuilds a table by copying it into __temp__,
    // and the SELECT names the real table, which is what the listener matched.
    return new QueryExecuted(
        'insert into "__temp__transactions" ("id", "user_id") select "id", "user_id" from "transactions"',
        [],
        1.0,
        app(DatabaseManager::class)->connection(),
    );
}

it('does not reach for the cache table while a migration is building it', function (): void {
    $window = new MigrationWindow;
    $window->open();

    listenerOverACachelessStore($window)->handle(theTableRebuildStatement());
})->throwsNoExceptions();

it('still invalidates the badges for an ordinary write once the run is over', function (): void {
    $window = new MigrationWindow;
    $window->open();
    $window->close();

    // The same statement, outside the window, reaches a cache table that is
    // not there — which is exactly what killed the first-launch run.
    expect(fn () => listenerOverACachelessStore($window)->handle(theTableRebuildStatement()))
        ->toThrow(QueryException::class);
});
