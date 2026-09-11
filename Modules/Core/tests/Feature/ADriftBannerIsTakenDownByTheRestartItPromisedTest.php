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
    // The dedup-key trigger comes along: releasing the key is half of what a
    // real acknowledgement does, and a fixture without it would let a
    // withdrawal look like it worked while leaving the kind unraisable.
    $this->sourcePath = RealSqliteFixture::create('driftbanner-source', [
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

/**
 * Written through a bare PDO on a purged connection: both the optimisations
 * listener and Laravel's own connector re-apply journal_mode the moment a
 * framework connection opens, so a drift set any other way never survives to
 * be read.
 */
function driftBannerWriteJournalMode(string $path, string $mode): void
{
    $pdo = new PDO('sqlite:'.$path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = '.$mode);
    unset($pdo);
}

// One boot with only the health check listening, since the optimisations
// provider would repair the pragma before the check ever read it.
function driftBannerBoot(): void
{
    $app = app();

    /** @var DatabaseManager $db */
    $db = $app->make(DatabaseManager::class);
    /** @var Dispatcher $events */
    $events = $app->make(Dispatcher::class);

    $events->forget(ConnectionEstablished::class);

    (new HealthCheckServiceProvider($app))->boot($events);

    $db->purge('sqlite');

    $events->dispatch(new ConnectionEstablished($db->connection('sqlite')));

    $app->instance(BootProbeState::class, new BootProbeState);
}

function driftBannerSetConfig(string $key, ?string $value): void
{
    /** @var Repository $config */
    $config = app()->make(Repository::class);
    $config->set('database.connections.sqlite.'.$key, $value);
}

/**
 * @return int the open rows of this kind the banner would still draw
 */
function driftBannerOpenCount(string $kind): int
{
    return SystemAlert::query()->where('kind', $kind)->whereNull('acknowledged_at')->count();
}

// A backup that failed its integrity check is the event itself, not a state to
// re-read. It is raised alongside the drift so a withdrawal that swept the
// table rather than the one kind it healed is caught by the same run that
// proves the sweep happens at all.
function driftBannerLatchingAlert(): int
{
    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->create([
        'user_id' => null,
        'dedup_key' => null,
        'kind' => 'backup_corrupt',
        'severity' => 'critical',
        'message' => 'The backup written at 2026-09-11 09:00 failed integrity check.',
        'metadata' => null,
    ]);

    return $alert->id;
}

it('takes the WAL banner down on the restart that put WAL back', function (): void {
    /** @var string $path */
    $path = $this->sourcePath;

    driftBannerSetConfig('journal_mode', null);
    driftBannerWriteJournalMode($path, 'DELETE');
    driftBannerBoot();

    expect(driftBannerOpenCount('wal_mode_missing'))
        ->toBe(1, 'The drifted boot should have raised exactly one open wal_mode_missing row.');

    $latchingId = driftBannerLatchingAlert();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');
    driftBannerWriteJournalMode($path, 'WAL');

    driftBannerBoot();

    expect(driftBannerOpenCount('wal_mode_missing'))
        ->toBe(0, 'The restart the banner promised would clear this left it standing.');

    expect(SystemAlert::query()->where('kind', 'wal_mode_missing')->whereNotNull('acknowledged_at')->count())
        ->toBe(1, 'The row is resolved, not deleted — the drift still happened and the history says so.');

    expect(SystemAlert::query()->whereKey($latchingId)->whereNull('acknowledged_at')->exists())
        ->toBeTrue('A corrupt-backup alert records an event and must survive an unrelated kind healing.');
});

it('takes the durability banner down on the restart that put synchronous back', function (): void {
    // Unset rather than drifted on the file: synchronous is a property of the
    // connection, not of the database, so an unconfigured connection opens at
    // SQLite's own FULL and that IS the drift the listener reads.
    driftBannerSetConfig('synchronous', null);
    driftBannerBoot();

    expect(driftBannerOpenCount('synchronous_misconfigured'))
        ->toBe(1, 'A connection opened without the configured level should have raised one open row.');

    driftBannerSetConfig('synchronous', 'NORMAL');
    driftBannerBoot();

    expect(driftBannerOpenCount('synchronous_misconfigured'))
        ->toBe(0, 'The restart the banner promised would clear this left it standing.');
});

it('raises the kind again when the pragma drifts a second time', function (): void {
    /** @var string $path */
    $path = $this->sourcePath;

    driftBannerSetConfig('journal_mode', null);

    driftBannerWriteJournalMode($path, 'DELETE');
    driftBannerBoot();

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);

    $db->purge('sqlite');
    driftBannerWriteJournalMode($path, 'WAL');
    driftBannerBoot();

    $db->purge('sqlite');
    driftBannerWriteJournalMode($path, 'DELETE');
    driftBannerBoot();

    // The withdrawal released the dedup key and the recency window counts only
    // open rows, so the drift that came back is reported rather than swallowed.
    expect(driftBannerOpenCount('wal_mode_missing'))
        ->toBe(1, 'A drift that came back has to be raised again.');
});
