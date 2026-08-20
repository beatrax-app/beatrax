<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Exceptions\UnsafeBackupPathException;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    // On-disk source: VACUUM INTO needs real tables to copy.
    $this->sourcePath = RealSqliteFixture::create('backup-test-source');

    // The command uses the named `sqlite` connection, so only that one moves
    // to the on-disk file; `sqlite_testing` (`:memory:`) stays the framework
    // default so RefreshDatabase and SystemAlert::create() keep working.
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    // UserDataPathService roots every path at NATIVEPHP_STORAGE_PATH when it
    // is set, so a per-test temp root leaves the real backups untouched.
    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    if (is_dir($backupsDir)) {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $file) {
            if (is_file((string) $file)) {
                @unlink((string) $file);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});

it('produces a chmod-600 .sqlite + .meta.json pair when invoked with --force', function (): void {
    $this->artisan('db:backup', ['--force' => true])
        ->expectsOutputToContain('Backup written:')
        ->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    expect(is_dir($backupsDir))->toBeTrue();

    $sqliteFiles = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite');
    $metaFiles = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite.meta.json');

    expect($sqliteFiles)->toHaveCount(1, 'Expected exactly one .sqlite backup file.');
    expect($metaFiles)->toHaveCount(1, 'Expected exactly one .meta.json sidecar.');

    $sqlite = (string) $sqliteFiles[0];
    $meta = (string) $metaFiles[0];

    expect(fileperms($sqlite) & 0o777)->toBe(0o600, 'Backup file must be mode 0600.');
    expect(fileperms($meta) & 0o777)->toBe(0o600, 'Meta sidecar must be mode 0600.');

    $decoded = json_decode((string) file_get_contents($meta), true);
    expect($decoded)->toBeArray();
    expect($decoded)->toHaveKeys(['data_version', 'started_at', 'completed_at', 'integrity']);
    expect($decoded['integrity'])->toBe('ok');
    expect($decoded['data_version'])->toBeInt();
});

it('skips a second invocation when data_version is unchanged and --force is absent', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $afterFirst = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite');
    expect($afterFirst)->toHaveCount(1);

    $this->artisan('db:backup')
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    $afterSecond = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite');
    expect($afterSecond)->toHaveCount(1, 'Smart-skip path must NOT create a second .sqlite file.');
});

it('prunes pre-seeded historical backups outside the 7-daily + 4-Sunday keep set', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    // Eight dailies with no Sunday among them, so the weekly-rescue arm of
    // the retention policy stays out of the assertion below.
    $seedDates = [
        '2026-04-22-030000', // Wed
        '2026-04-23-030000', // Thu
        '2026-04-24-030000', // Fri
        '2026-04-25-030000', // Sat
        '2026-04-27-030000', // Mon (skip Sun 26 to keep test simple)
        '2026-04-28-030000', // Tue
        '2026-04-29-030000', // Wed
        '2026-04-30-030000', // Thu
    ];

    foreach ($seedDates as $stamp) {
        $files->put($backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$stamp.'.sqlite', 'seeded');
        $files->put(
            $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$stamp.'.sqlite.meta.json',
            json_encode(['data_version' => 1, 'started_at' => 'x', 'completed_at' => 'x', 'integrity' => 'ok']) ?: '',
        );
    }

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    $remaining = (array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite');
    // 8 seeded + 1 fresh = 9 dailies; retention keeps the 7 newest, so
    // 04-22 and 04-23 are pruned and nothing is rescued as a weekly.
    expect(count($remaining))->toBe(7, 'Expected exactly 7 .sqlite files after pruning.');
});

// VACUUM INTO interpolates its destination literally, so the command rejects
// bytes that could break out of the quoted string. The storage root is where
// such a byte would realistically arrive from.
it('refuses a destination path carrying a byte VACUUM INTO must never see', function (): void {
    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    putenv('NATIVEPHP_STORAGE_PATH='.$storageRoot."\n".'injected');

    expect(fn () => $this->artisan('db:backup', ['--force' => true])->run())
        ->toThrow(UnsafeBackupPathException::class, 'unsafe byte');
});

it('refuses to run against a driver it cannot VACUUM INTO', function (): void {
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.driver', 'pgsql');

    expect(fn () => $this->artisan('db:backup', ['--force' => true])->run())
        ->toThrow(BackupNotSupportedException::class, 'only supported on the sqlite driver');
});

it('refuses to run when no sqlite database path is configured', function (): void {
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', '');

    expect(fn () => $this->artisan('db:backup', ['--force' => true])->run())
        ->toThrow(BackupNotSupportedException::class, 'is not configured');
});
