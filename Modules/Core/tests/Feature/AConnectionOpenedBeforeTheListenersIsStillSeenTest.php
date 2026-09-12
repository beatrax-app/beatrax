<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Internal\Providers\AlreadyOpenConnectionsProvider;
use Modules\Core\Internal\Providers\HealthCheckServiceProvider;
use Modules\Core\Internal\Providers\SqliteOptimizationsProvider;
use Tests\Helpers\RealSqliteFixture;

// NativePHP's provider makes `nativephp` the default and runs PRAGMA
// statements through it inside its own boot(), so on the desktop the
// connection is already open by the time this module attaches its listeners.
// Measured there: busy_timeout 5000 against a configured 30000.
beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('alreadyopen-source', [
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
        "INSERT INTO system_alerts (id, user_id, kind, severity, message)
            VALUES (1, NULL, 'wal_mode_missing', 'warning', 'stale')",
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
    // teardown rollback would re-open the on-disk connection and fire the
    // optimisations PRAGMA against a path that is gone.
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

/**
 * @return array{0: DatabaseManager, 1: Dispatcher}
 */
function alreadyOpenBootWithConnectionFirst(): array
{
    $app = app();

    /** @var DatabaseManager $db */
    $db = $app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $app->make(Dispatcher::class);

    $events->forget(ConnectionEstablished::class);
    $db->purge('sqlite');

    // The ordering under test: the connection is opened, and only afterwards
    // do the two providers attach the listeners that configure and inspect it.
    $db->connection('sqlite');

    (new SqliteOptimizationsProvider($app))->boot($events);
    (new HealthCheckServiceProvider($app))->boot($events);

    return [$db, $events];
}

function alreadyOpenAlertIsOpen(DatabaseManager $db): bool
{
    return $db->connection('sqlite')
        ->table('system_alerts')
        ->where('id', 1)
        ->whereNull('acknowledged_at')
        ->exists();
}

// Without this the rest is vacuous: if attaching the listeners after the
// connection still delivered the event, there would be nothing to catch up on
// and the provider under test could be deleted with every assertion passing.
it('does not deliver the event to listeners attached after the connection opened', function (): void {
    [$db, $events] = alreadyOpenBootWithConnectionFirst();

    $seen = [];
    $events->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$seen): void {
        $seen[] = $event->connection->getName();
    });

    expect($seen)->toBe([])
        ->and(alreadyOpenAlertIsOpen($db))->toBeTrue();
});

it('replays the event for a connection that was already open', function (): void {
    [$db, $events] = alreadyOpenBootWithConnectionFirst();

    $seen = [];
    $events->listen(ConnectionEstablished::class, function (ConnectionEstablished $event) use (&$seen): void {
        $seen[] = $event->connection->getName();
    });

    (new AlreadyOpenConnectionsProvider(app()))->boot($events, $db);

    expect($seen)->toContain('sqlite');
});

it('takes down a drift banner the desktop would otherwise carry forever', function (): void {
    [$db, $events] = alreadyOpenBootWithConnectionFirst();

    expect(alreadyOpenAlertIsOpen($db))->toBeTrue();

    (new AlreadyOpenConnectionsProvider(app()))->boot($events, $db);

    expect(alreadyOpenAlertIsOpen($db))->toBeFalse();
});
