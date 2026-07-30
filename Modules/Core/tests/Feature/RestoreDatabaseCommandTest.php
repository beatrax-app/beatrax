<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\RealSqliteFixture;

/*
 * Drives the four refusal paths of `php artisan db:restore`:
 *  (a) source file missing → exit 1 + "Source file not found"
 *  (b) source file corrupt → exit 1 + "failed integrity check"
 *  (c) app NOT in maintenance mode AND --force-maintenance absent → exit 1
 *  (d) non-TTY context AND --confirm absent → exit 1
 *
 * Every refusal MUST leave the live DB file untouched. The happy path
 * + pre-restore snapshot story is covered by RestoreSuccessPathTest.
 */

beforeEach(function (): void {
    // Build an on-disk "live" SQLite file the command will operate
    // against. Rebind the named `sqlite` connection at this path so
    // the command's VACUUM INTO + integrity_check reads run against
    // a real on-disk database. RefreshDatabase keeps using the
    // :memory: sqlite_testing connection for the SystemAlert table.
    $this->livePath = RealSqliteFixture::create('restore-test-live');

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->livePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-restore-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));

    // The command resolves the maintenance-mode down marker through
    // UserDataPathService::framework('down'), so the test drives
    // maintenance state by writing/removing that exact file rather
    // than `php artisan down`, which targets the framework's own
    // (un-redirected) storage path.
    $this->downMarker = (new UserDataPathService)->framework('down');

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->ensureDirectoryExists(dirname($this->downMarker));

    // markDown / markUp give each test deterministic control over the
    // maintenance marker the command inspects.
    $this->markDown = function () use ($files): void {
        $files->put($this->downMarker, '');
    };
    $this->markUp = function () use ($files): void {
        if ($files->exists($this->downMarker)) {
            $files->delete($this->downMarker);
        }
    };

    // Ensure the app is "up" before each test so we can deterministically
    // assert refusal in (c).
    ($this->markUp)();
});

afterEach(function (): void {
    /** @var string $livePath */
    $livePath = $this->livePath;
    RealSqliteFixture::cleanup($livePath);

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

    putenv('NATIVEPHP_STORAGE_PATH');
});

it('refuses with exit 1 when the source file does not exist', function (): void {
    ($this->markDown)();

    $this->artisan('db:restore', [
        'path' => '/nonexistent/path/to/missing.sqlite',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('Source file not found')
        ->assertExitCode(1);
});

it('refuses with exit 1 when the source file fails PRAGMA integrity_check', function (): void {
    ($this->markDown)();

    // Build a source fixture, then truncate it to 100 bytes so the
    // SQLite header is incomplete and PRAGMA integrity_check refuses.
    $sourcePath = RealSqliteFixture::create('restore-corrupt-source');
    file_put_contents($sourcePath, substr((string) file_get_contents($sourcePath), 0, 100));

    /** @var string $livePath */
    $livePath = $this->livePath;
    $liveContentsBefore = (string) file_get_contents($livePath);

    try {
        $this->artisan('db:restore', [
            'path' => $sourcePath,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('failed integrity check')
            ->assertExitCode(1);

        // The live DB must be untouched.
        expect((string) file_get_contents($livePath))->toBe($liveContentsBefore);
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});

it('refuses with exit 1 when the app is NOT in maintenance mode and --force-maintenance is absent', function (): void {
    $sourcePath = RealSqliteFixture::create('restore-maint-source');

    /** @var string $livePath */
    $livePath = $this->livePath;
    $liveContentsBefore = (string) file_get_contents($livePath);

    try {
        $this->artisan('db:restore', [
            'path' => $sourcePath,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('maintenance mode')
            ->assertExitCode(1);

        // The live DB must be untouched.
        expect((string) file_get_contents($livePath))->toBe($liveContentsBefore);
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});

it('refuses with exit 1 in a non-TTY context when --confirm is absent', function (): void {
    ($this->markDown)();

    $sourcePath = RealSqliteFixture::create('restore-noconfirm-source');

    /** @var string $livePath */
    $livePath = $this->livePath;
    $liveContentsBefore = (string) file_get_contents($livePath);

    try {
        // No --confirm flag passed; the test harness is non-TTY so the
        // command must fail rather than hang waiting on stdin.
        $this->artisan('db:restore', ['path' => $sourcePath])
            ->expectsOutputToContain('--confirm')
            ->assertExitCode(1);

        expect((string) file_get_contents($livePath))->toBe($liveContentsBefore);
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});

/*
 * The two configuration refusals, which happen before any file is read.
 *
 * Both raise BackupNotSupportedException rather than returning FAILURE like
 * the file-level refusals do, and the distinction is what the operator does
 * next: nothing about the machine needs fixing and no retry helps, so this
 * points at configuration rather than at the disk or the file they just
 * handed the command.
 */

it('refuses when the configured connection is not sqlite', function (): void {
    ($this->markDown)();

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.driver', 'pgsql');

    expect(fn () => Artisan::call('db:restore', ['path' => $this->livePath, '--confirm' => true]))
        ->toThrow(BackupNotSupportedException::class, 'only supported on the sqlite driver');
});

it('refuses when no sqlite database path is configured', function (mixed $configured): void {
    ($this->markDown)();

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $configured);

    expect(fn () => Artisan::call('db:restore', ['path' => $this->livePath, '--confirm' => true]))
        ->toThrow(BackupNotSupportedException::class, 'is not configured');
})->with([
    'unset' => [null],
    'empty string' => [''],
]);
