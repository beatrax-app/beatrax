<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Models\SystemAlert;
use Tests\Helpers\RealSqliteFixture;

/*
 * Drives the HealthCheckServiceProvider's ConnectionEstablished listener:
 *  (a) Clean state (WAL active + synchronous = 1) → no system_alerts
 *      rows written.
 *  (b) PRAGMA drift (journal_mode = DELETE) → exactly one
 *      system_alerts(wal_mode_missing, warning) row written.
 *  (c) Re-firing the listener within the same process (BootProbeState
 *      singleton intact) → still exactly one row.
 *  (d) Resetting BootProbeState + re-firing → another row is permitted.
 *
 * The cross-process recency guard (1-hour suppression via
 * SystemAlert::query) is exercised by re-firing with the singleton
 * reset but the previous row still present.
 */

beforeEach(function (): void {
    // Build an on-disk SQLite fixture so the listener has a real
    // journal_mode to read. The fixture writes `PRAGMA journal_mode =
    // WAL` against the file before any framework connection opens —
    // overrides happen per test below.
    //
    // The fixture's system_alerts schema is extended so the
    // wal_mode_missing alert insert lands with a sensible default for
    // `created_at` (matching the prod migration's useCurrent()).
    $this->sourcePath = RealSqliteFixture::create('boothealth-source', [
        'CREATE TABLE transactions (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NULL,
            amount_minor INTEGER NOT NULL,
            currency TEXT NOT NULL,
            booked_at TEXT NOT NULL
        )',
        'CREATE TABLE system_alerts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NULL,
            kind TEXT NOT NULL,
            severity TEXT NOT NULL,
            message TEXT NOT NULL,
            metadata TEXT NULL,
            created_at TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
            acknowledged_at TEXT NULL
        )',
    ]);

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);
    $config->set('database.default', 'sqlite');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    // Reset the BootProbeState singleton between tests so the in-process
    // dedupe gate starts fresh.
    $this->app->instance(BootProbeState::class, new BootProbeState);
});

afterEach(function (): void {
    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);
});

it('BootProbeState is bound as a container singleton', function (): void {
    $a = $this->app->make(BootProbeState::class);
    $b = $this->app->make(BootProbeState::class);
    expect($a)->toBe($b);

    // Default booted flag is false.
    expect($a->booted)->toBeFalse();
});

it('writes no system_alerts row on a clean boot (WAL active + synchronous NORMAL)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    // Force the on-disk fixture into the documented healthy state via
    // raw PDO so the listener observes the canonical happy path.
    $pdo = new PDO('sqlite:'.$this->sourcePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    unset($pdo);

    $db->purge('sqlite');

    // Fire the listener manually so we do not depend on Laravel's boot
    // sequence opening a connection during artisan dispatch.
    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    expect(SystemAlert::query()->whereIn('kind', ['wal_mode_missing', 'synchronous_misconfigured'])->count())->toBe(0);
});

it('writes exactly one wal_mode_missing alert on PRAGMA drift', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    // Force the fixture into journal_mode = DELETE via raw PDO BEFORE
    // any framework connection opens.
    $pdo = new PDO('sqlite:'.$this->sourcePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    unset($pdo);

    // Disable the SqliteOptimizationsProvider's PRAGMA re-application
    // via the per-connection config keys so the listener sees the
    // drifted value rather than the re-applied WAL.
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.journal_mode', null);
    $config->set('database.connections.sqlite.synchronous', null);
    $db->purge('sqlite');

    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    $alerts = SystemAlert::query()->where('kind', 'wal_mode_missing')->get();
    expect($alerts)->toHaveCount(1);
    /** @var SystemAlert $alert */
    $alert = $alerts->first();
    expect($alert->severity)->toBe('warning');
});

it('re-fires the listener in-process without writing duplicate rows (BootProbeState dedupe)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $pdo = new PDO('sqlite:'.$this->sourcePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    unset($pdo);

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.journal_mode', null);
    $config->set('database.connections.sqlite.synchronous', null);
    $db->purge('sqlite');

    // Fire twice in the same process. The BootProbeState singleton's
    // booted flag prevents the second invocation from doing any work.
    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));
    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    expect(SystemAlert::query()->where('kind', 'wal_mode_missing')->count())->toBe(1);
});

it('does not write a duplicate within an hour even if BootProbeState is reset (cross-process recency guard)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $pdo = new PDO('sqlite:'.$this->sourcePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    unset($pdo);

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.journal_mode', null);
    $config->set('database.connections.sqlite.synchronous', null);
    $db->purge('sqlite');

    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    // Simulate a new process by resetting the BootProbeState singleton.
    // The recency query against system_alerts should still suppress
    // the duplicate write because the previous row is < 1h old.
    $this->app->instance(BootProbeState::class, new BootProbeState);

    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    expect(SystemAlert::query()->where('kind', 'wal_mode_missing')->count())->toBe(1);
});
