<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Internal\Providers\HealthCheckServiceProvider;
use Modules\Core\Models\SystemAlert;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    // On disk so the listener has a real journal_mode to read; system_alerts is
    // spelled out for the created_at default the prod migration gives useCurrent().
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
            acknowledged_at TEXT NULL,
            dedup_key TEXT NULL
        )',
        'CREATE UNIQUE INDEX system_alerts_dedup_key_unique ON system_alerts (dedup_key)',
    ]);

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);
    $config->set('database.default', 'sqlite');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    // Reset the in-process dedupe gate between tests.
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

it('BootProbeState is bound as a container singleton', function (): void {
    $a = $this->app->make(BootProbeState::class);
    $b = $this->app->make(BootProbeState::class);
    expect($a)->toBe($b);
    expect($a->booted)->toBeFalse();
});

it('writes no system_alerts row on a clean boot (WAL active + synchronous NORMAL)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    $pdo = new PDO('sqlite:'.$this->sourcePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    unset($pdo);

    $db->purge('sqlite');

    // Fire manually rather than lean on Laravel's boot sequence to connect.
    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    expect(SystemAlert::query()->whereIn('kind', ['wal_mode_missing', 'synchronous_misconfigured'])->count())->toBe(0);
});

it('writes exactly one wal_mode_missing alert on PRAGMA drift', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $this->app->make(Dispatcher::class);

    // Drift journal_mode before any framework connection opens.
    $pdo = new PDO('sqlite:'.$this->sourcePath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = DELETE');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    unset($pdo);

    // SqliteOptimizationsProvider's listener re-applies WAL the moment a
    // connection opens, so drop every listener and re-register only
    // HealthCheck below.
    $events->forget(ConnectionEstablished::class);

    // Laravel's SQLiteConnector also re-applies journal_mode from the
    // connection config on open, listener or no listener.
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.journal_mode', null);

    (new HealthCheckServiceProvider($this->app))
        ->boot($events);

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

    // Only HealthCheck may see the drift: listeners and connector PRAGMA off.
    $events->forget(ConnectionEstablished::class);
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.journal_mode', null);

    (new HealthCheckServiceProvider($this->app))
        ->boot($events);

    $db->purge('sqlite');

    // Twice in one process — BootProbeState's booted flag stops the second.
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

    // Only HealthCheck may see the drift: listeners and connector PRAGMA off,
    // then re-register HealthCheck alone.
    $events->forget(ConnectionEstablished::class);
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.journal_mode', null);

    (new HealthCheckServiceProvider($this->app))
        ->boot($events);

    $db->purge('sqlite');

    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    // A fresh singleton stands in for a new process; the recency query still
    // suppresses the write because the previous row is under an hour old.
    $this->app->instance(BootProbeState::class, new BootProbeState);

    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    expect(SystemAlert::query()->where('kind', 'wal_mode_missing')->count())->toBe(1);
});
