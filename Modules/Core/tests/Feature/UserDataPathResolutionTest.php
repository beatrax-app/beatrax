<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Modules\Core\Public\Services\UserDataPathService;

beforeEach(function (): void {
    // Cached config freezes resolved paths into a flat array, masking the
    // env-var branch entirely — refuse to run under one.
    expect($this->app->configurationIsCached())->toBeFalse();

    $this->tmpRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8));

    // SQLite will not create the database file's parent directory. And
    // NATIVEPHP_STORAGE_PATH *is* the storage root, so app/ sits directly
    // under <tmp> with no extra `storage/` segment.
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'database', 0o755, true);
    // SQLiteConnector refuses a database file that does not exist, and
    // SqliteOptimizationsProvider's `PRAGMA journal_mode` runs on
    // ConnectionEstablished — before migrate:fresh could create it. Both
    // bootstrap/app.php files touch it during ->booting(); so must this root.
    touch($this->tmpRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite');
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups', 0o755, true);
    mkdir($this->tmpRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'secrets', 0o755, true);

    // UserDataPathService reads getenv() directly, so putenv() is the only
    // mechanism that influences it; clearing it means passing no `=` value.
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
    // A stray bootstrap/cache/config.php would freeze a stale path.
    expect($this->app->configurationIsCached())->toBeFalse();

    /** @var string $tmpRoot */
    $tmpRoot = $this->tmpRoot;

    $databaseFile = UserDataPathService::databaseFile();
    expect($databaseFile)->toBe(
        $tmpRoot.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'database.sqlite',
    );

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

    // VACUUM INTO needs a real on-disk source under the simulated root.
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
    putenv('NATIVEPHP_STORAGE_PATH');

    expect($this->app->configurationIsCached())->toBeFalse();

    // base_path(), not storage_path(): the harness relocates storage_path()
    // per test, which would not pin the unset-env fallback.
    expect(UserDataPathService::databaseFile())->toBe(base_path('database/database.sqlite'));
    expect(UserDataPathService::backupsPath())->toBe(base_path('storage/app/backups'));
    expect(UserDataPathService::secretsPath())->toBe(base_path('storage/app/secrets'));
});
