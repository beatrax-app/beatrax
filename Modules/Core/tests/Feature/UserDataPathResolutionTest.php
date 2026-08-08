<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\UserDataPathService;

/*
 * Drives path resolution end-to-end under a simulated NativePHP storage
 * root: with NATIVEPHP_STORAGE_PATH pointing at a per-test temp directory,
 * UserDataPathService and the artisan commands that consume it must land
 * every file under that root instead of the project tree.
 *
 * Coverage:
 *  - migrate:fresh against an sqlite connection pointed at
 *    UserDataPathService::databaseFile() creates a real SQLite file under
 *    <tmp>/database/database.sqlite.
 *  - db:backup writes its backup artifact under <tmp>/app/backups/
 *    (NATIVEPHP_STORAGE_PATH is itself the storage root).
 *  - UserDataPathService::secretsPath() resolves to <tmp>/app/secrets.
 *  - With NATIVEPHP_STORAGE_PATH cleared, every accessor resolves to the
 *    project-rooted paths used in local development (A2 regression guard).
 */

beforeEach(function (): void {
    // Cached config freezes resolved paths into a flat array, which would
    // mask the env-var branch entirely (Pitfall 4). Refuse to run if the
    // harness somehow booted with cached config.
    expect($this->app->configurationIsCached())->toBeFalse();

    // Collision-free per-test root under the system temp dir.
    $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8));

    // SQLite will not create the parent directory of the database file,
    // and db:backup writes into the storage app/backups directory
    // (Pitfall 5) — create the directory tree the simulated build expects
    // before any command runs against the root. NATIVEPHP_STORAGE_PATH IS
    // the storage root, so the app/ tree sits directly under <tmp> (no
    // extra `storage/` segment) — matching UserDataPathService::appPath().
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'database', 0o755, true);
    // Laravel's SQLiteConnector refuses to connect to a database file that
    // does not exist, and SqliteOptimizationsProvider's `PRAGMA journal_mode`
    // runs on ConnectionEstablished — before migrate:fresh can create it. The
    // real roots do not hit this because both bootstrap/app.php files touch
    // the file during ->booting(); the simulated root has to do the same.
    touch($this->tmpRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups', 0o755, true);
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'secrets', 0o755, true);

    // UserDataPathService reads getenv() directly, so putenv() is the only
    // mechanism that influences it (Pitfall 3). Clear with no `=` value.
    putenv('NATIVEPHP_STORAGE_PATH='.$this->tmpRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;
    if (! is_dir($tmpRoot)) {
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tmpRoot, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    /** @var SplFileInfo $entry */
    foreach ($iterator as $entry) {
        if ($entry->isDir()) {
            @rmdir($entry->getPathname());
        } else {
            @unlink($entry->getPathname());
        }
    }
    @rmdir($tmpRoot);
});

it('creates the SQLite file under the simulated storage root when migrate:fresh runs', function (): void {
    // Pitfall 4 guard — a stray bootstrap/cache/config.php would freeze a
    // stale path and produce a false green/red.
    expect($this->app->configurationIsCached())->toBeFalse();

    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;

    $databaseFile = UserDataPathService::databaseFile();
    expect($databaseFile)->toBe(
        $tmpRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite',
    );

    // Point the `sqlite` connection at the env-resolved database file so
    // migrate:fresh writes into <tmp>/database (BackupDatabaseCommandTest
    // lines 39-45 rebind pattern). The <tmp>/database directory already
    // exists from beforeEach (Pitfall 5 — SQLite will not create it).
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $databaseFile);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->artisan('migrate:fresh', ['--database' => 'sqlite'])->assertSuccessful();

    expect(file_exists($databaseFile))->toBeTrue(
        'migrate:fresh must create the SQLite file under the simulated storage root.',
    );
});

it('writes a db:backup artifact under the simulated storage root backups directory', function (): void {
    expect($this->app->configurationIsCached())->toBeFalse();

    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;

    // Stand up a real on-disk source database under the simulated root so
    // db:backup's VACUUM INTO has a valid file to copy.
    $databaseFile = UserDataPathService::databaseFile();

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $databaseFile);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->artisan('migrate:fresh', ['--database' => 'sqlite'])->assertSuccessful();
    $db->purge('sqlite');

    $backupsDir = UserDataPathService::backupsPath();
    expect($backupsDir)->toBe(
        $tmpRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups',
    );

    // db:backup resolves UserDataPathService via DI and writes under
    // backupsPath() = <tmp>/storage/app/backups.
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    $artifacts = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'*');
    expect($artifacts)->not->toBeEmpty(
        'db:backup must write at least one artifact under the simulated backups directory.',
    );
});

it('resolves the OAuth secrets path under the simulated storage root', function (): void {
    expect($this->app->configurationIsCached())->toBeFalse();

    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;

    expect(UserDataPathService::secretsPath())->toBe(
        $tmpRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'secrets',
    );
});

it('resolves to today\'s project-rooted paths when no NativePHP storage env is set', function (): void {
    // A2 / local-dev-parity regression guard: clear the env var and assert the
    // accessors stay byte-identical to today's project-rooted helpers, so
    // a future refactor cannot silently shift the dev-mode paths.
    putenv('NATIVEPHP_STORAGE_PATH');

    expect($this->app->configurationIsCached())->toBeFalse();

    // base_path() rather than storage_path(): this pins the unset-env
    // fallback, which is projectRoot()/storage. storage_path() is relocated
    // per test so nothing on disk outlives its test.
    expect(UserDataPathService::databaseFile())->toBe(base_path('database/database.sqlite'));
    expect(UserDataPathService::backupsPath())->toBe(base_path('storage/app/backups'));
    expect(UserDataPathService::secretsPath())->toBe(base_path('storage/app/secrets'));
});
