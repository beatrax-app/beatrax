<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Internal\Backup\LiveDatabaseTransplant;

// `purge()` takes a connection off the DatabaseManager and out of nobody else.
// A cache store built over it keeps the object, and the framework repairs that
// orphan by handing it the live connection's PDO — two Connections over one
// handle, the second with a transaction counter of zero. Its next `increment()`
// issues a bare BEGIN on a handle already inside a transaction.

/**
 * @return array{0: string, 1: string, 2: string}
 */
function transplantProbePaths(): array
{
    $directory = sys_get_temp_dir().'/beatrax-purge-probe-'.bin2hex(random_bytes(6));
    mkdir($directory, 0o700, true);

    return [$directory, $directory.'/live.sqlite', $directory.'/source.sqlite'];
}

function seedTransplantProbeSchema(string $path): void
{
    $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $pdo->exec('CREATE TABLE cache (key TEXT PRIMARY KEY, value TEXT, expiration INTEGER)');
    $pdo->exec('CREATE TABLE cache_locks (key TEXT PRIMARY KEY, owner TEXT, expiration INTEGER)');
    $pdo->exec('CREATE TABLE ledger_rows (id INTEGER PRIMARY KEY, note TEXT)');
}

it('does not leave a purged connection inside the cache store built over it', function (): void {
    [$directory, $live, $source] = transplantProbePaths();

    seedTransplantProbeSchema($live);
    seedTransplantProbeSchema($source);

    /** @var ConfigRepository $config */
    $config = app(ConfigRepository::class);
    $config->set('database.connections.purge_probe', [
        'driver' => 'sqlite',
        'database' => $live,
        'prefix' => '',
        'foreign_key_constraints' => true,
        'transaction_mode' => 'IMMEDIATE',
    ]);
    $config->set('cache.stores.purge_probe', [
        'driver' => 'database',
        'connection' => 'purge_probe',
        'table' => 'cache',
        'lock_table' => 'cache_locks',
    ]);

    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    // Resolved and used before the purge, which is the whole shape: the store
    // that boots first is the one holding the connection a later purge drops.
    Cache::store('purge_probe')->forever('probe:generation', 1);

    app(LiveDatabaseTransplant::class)($source, $live, $directory.'/undo.sqlite');

    $db->connection('purge_probe')->transaction(static function (): void {
        $db = app(DatabaseManager::class);
        $db->connection('purge_probe')->table('ledger_rows')->insert(['note' => 'after the restore']);
        Cache::store('purge_probe')->increment('probe:generation');
    });

    expect($db->connection('purge_probe')->table('ledger_rows')->count())
        ->toBe(1, 'a write wrapped in a transaction must survive the restore that preceded it');

    $db->purge('purge_probe');
    Cache::forgetDriver('purge_probe');

    foreach ([$live, $source, $live.'-wal', $live.'-shm', $source.'-wal', $source.'-shm'] as $file) {
        @unlink($file);
    }
    @rmdir($directory);
});
