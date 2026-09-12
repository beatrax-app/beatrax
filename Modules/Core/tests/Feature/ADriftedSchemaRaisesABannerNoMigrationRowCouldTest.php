<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Modules\Core\Internal\Backup\BackupFreshness;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Internal\Listeners\HealthCheckListener;
use Modules\Core\Internal\Providers\HealthCheckServiceProvider;
use Modules\Core\Internal\Services\SchemaShapeHealthCheck;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Support\SchemaShape;
use Psr\Log\LoggerInterface;
use Tests\Helpers\RealSqliteFixture;

const SCHEMA_DRIFT_KIND = 'schema_shape_drifted';

// A cascade and both enum guards, on a file small enough to drift by hand. The
// dedup-key trigger comes along because releasing the key is half of what a
// real acknowledgement does, and without it a withdrawal would look like it
// worked while leaving the kind unraisable.
beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('schemadrift-source', [
        "CREATE TABLE users (
            id INTEGER PRIMARY KEY,
            receipt_conflict_resolution varchar NOT NULL DEFAULT 'unset'
        )",
        ...array_values(SchemaShape::enumGuardTriggers()),
        'CREATE TABLE system_alerts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NULL,
            kind TEXT NOT NULL,
            severity TEXT NOT NULL,
            message TEXT NOT NULL,
            metadata TEXT NULL,
            created_at TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
            acknowledged_at TEXT NULL,
            dedup_key TEXT NULL
        )',
        'CREATE UNIQUE INDEX system_alerts_dedup_key_unique ON system_alerts (dedup_key)',
        'CREATE TRIGGER system_alerts_release_dedup_key AFTER UPDATE OF acknowledged_at ON system_alerts FOR EACH ROW
            WHEN NEW.acknowledged_at IS NOT NULL AND NEW.dedup_key IS NOT NULL
            BEGIN UPDATE system_alerts SET dedup_key = NULL WHERE id = NEW.id; END',
    ]);

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);
    $config->set('database.default', 'sqlite');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->app->instance(BootProbeState::class, new BootProbeState);
});

afterEach(function (): void {
    // Restore the default BEFORE deleting the fixture: RefreshDatabase's
    // teardown rollback would re-open the on-disk connection and fire
    // SqliteOptimizationsProvider's PRAGMA against a path that is gone.
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.default', 'sqlite_testing');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);
});

// One boot, with only the health check listening and a state nothing has spent.
// The order is the whole of it: a statement run to DRIFT the schema resolves
// this connection first, firing the event at the app's own listener against the
// schema as it was BEFORE -- and the one-shot probe is then already spent.
/**
 * @link ../../../../.docs/features/core/a-schema-the-migrations-table-vouched-for.md
 */
function schemaDriftBoot(): void
{
    $app = app();

    /** @var DatabaseManager $db */
    $db = $app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $app->make(Dispatcher::class);

    // Including the optimisations provider's, which would repair the pragmas
    // the checks beside this one read before they ever read them.
    $events->forget(ConnectionEstablished::class);

    $db->purge('sqlite');
    $connection = $db->connection('sqlite');

    $app->instance(BootProbeState::class, new BootProbeState);

    (new HealthCheckServiceProvider($app))->boot($events);

    $events->dispatch(new ConnectionEstablished($connection));
}

function schemaDriftStatement(string $sql): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $db->connection('sqlite')->statement($sql);
}

// A connection whose file is not there: SQLite refuses to open it, which is the
// shape of every read this check could fail on.
function schemaDriftPointTheDefaultAtNothing(): void
{
    /** @var Repository $config */
    $config = app(Repository::class);

    /** @var string $sourcePath */
    $sourcePath = test()->sourcePath;

    $config->set('database.connections.sqlite_unreadable', [
        'driver' => 'sqlite',
        'database' => $sourcePath.DIRECTORY_SEPARATOR.'gone.sqlite',
    ]);
    $config->set('database.default', 'sqlite_unreadable');
}

function schemaDriftOpenCount(): int
{
    return SystemAlert::query()
        ->where('kind', SCHEMA_DRIFT_KIND)
        ->whereNull('acknowledged_at')
        ->count();
}

it('says nothing about a schema that is the shape the migrations declare', function (): void {
    schemaDriftBoot();

    // The control for every assertion below it: a check that raised here would
    // make "no row" mean "nothing was looked at" rather than "nothing is wrong".
    expect(schemaDriftOpenCount())->toBe(0);

    /** @var SchemaShapeHealthCheck $health */
    $health = app(SchemaShapeHealthCheck::class);

    expect($health->severity())->toBe('ok');
    expect($health->message())->toBe('matches what the migrations declare');
});

it('raises one banner for a cascade the application never agreed to', function (): void {
    schemaDriftStatement(
        'create table drifted_children (
            id integer primary key,
            owner_id integer null,
            foreign key(owner_id) references users(id) on delete cascade
        )',
    );

    schemaDriftBoot();

    expect(schemaDriftOpenCount())->toBe(1);

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->where('kind', SCHEMA_DRIFT_KIND)->firstOrFail();

    // System-wide, because a drifted schema is a fact about this machine's own
    // file — owned, the row would ride the op log to a peer whose schema is fine.
    expect($alert->user_id)->toBeNull();
    expect($alert->severity)->toBe('warning');
    expect($alert->metadata)->toMatchArray(['cascading_tables' => 1, 'missing_triggers' => 0]);
});

it('raises the same banner for an enum guard a table rebuild ate', function (): void {
    foreach (array_keys(SchemaShape::enumGuardTriggers()) as $name) {
        schemaDriftStatement('DROP TRIGGER '.$name);
    }

    schemaDriftBoot();

    expect(schemaDriftOpenCount())->toBe(1);

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->where('kind', SCHEMA_DRIFT_KIND)->firstOrFail();

    expect($alert->metadata)->toMatchArray(['cascading_tables' => 0, 'missing_triggers' => 2]);
});

it('takes the banner down on the start that put the schema back', function (): void {
    schemaDriftStatement('DROP TRIGGER users_receipt_conflict_resolution_check_insert');
    schemaDriftBoot();

    expect(schemaDriftOpenCount())->toBe(1);

    foreach (SchemaShape::enumGuardTriggers() as $name => $definition) {
        schemaDriftStatement('DROP TRIGGER IF EXISTS '.$name);
        schemaDriftStatement($definition);
    }

    schemaDriftBoot();

    expect(schemaDriftOpenCount())->toBe(0);

    // Resolved, not deleted: the drift did happen and the history says so.
    expect(SystemAlert::query()->where('kind', SCHEMA_DRIFT_KIND)->whereNotNull('acknowledged_at')->count())->toBe(1);
});

it('stays quiet on a database whose tables the first migration has not built yet', function (): void {
    schemaDriftStatement('DROP TABLE users');

    schemaDriftBoot();

    // Every connection a fresh install opens during its first migrate reaches
    // the check before the table it asks about exists, and none of those is a
    // drifted schema to tell anybody about.
    expect(schemaDriftOpenCount())->toBe(0);
});

it('bounds the names it lists when a whole schema drifted at once', function (): void {
    foreach (range(1, 12) as $n) {
        schemaDriftStatement(
            "create table drifted_{$n} (
                id integer primary key,
                owner_id integer null,
                foreign key(owner_id) references users(id) on delete cascade
            )",
        );
    }

    /** @var SchemaShapeHealthCheck $health */
    $health = app(SchemaShapeHealthCheck::class);

    expect($health->message())->toContain('12 table(s)');
    expect($health->message())->toContain('and more');
});

it('answers with a warning rather than a throw when the schema cannot be read at all', function (): void {
    schemaDriftPointTheDefaultAtNothing();

    /** @var SchemaShapeHealthCheck $health */
    $health = app(SchemaShapeHealthCheck::class);

    expect($health->severity())->toBe('warning');
    expect($health->message())->toBe('sqlite_master could not be read');
});

it('lets the rest of boot run when sqlite_master cannot be read', function (): void {
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $connection = $db->connection('sqlite');

    schemaDriftPointTheDefaultAtNothing();

    // A diagnostic that halts the boot it is diagnosing is worse than no
    // diagnostic, so the pragma read this sits beside is not allowed to lose
    // its own boot to this one's connection.
    $listener = new HealthCheckListener(
        new BootProbeState,
        app(Clock::class),
        app(LoggerInterface::class),
        $db,
        app(SystemAlertWriter::class),
        app(BackupFreshness::class),
        app(SchemaShapeHealthCheck::class),
    );

    $listener(new ConnectionEstablished($connection));
})->throwsNoExceptions();
