<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
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

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'diederik-restore-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    $this->app->instance('core.backups_directory', $this->backupsDir);

    // Ensure the app is up before each test so we can deterministically
    // assert refusal in (c).
    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('up');
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

    // Always bring the app back up after a test that may have brought
    // it down — so the next test does not inherit a stuck maintenance
    // mode.
    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('up');
});

it('refuses with exit 1 when the source file does not exist', function (): void {
    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('down');

    $this->artisan('db:restore', [
        'path' => '/nonexistent/path/to/missing.sqlite',
        '--confirm' => true,
    ])
        ->expectsOutputToContain('Source file not found')
        ->assertExitCode(1);
});

it('refuses with exit 1 when the source file fails PRAGMA integrity_check', function (): void {
    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('down');

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
    /** @var Kernel $artisan */
    $artisan = $this->app->make(Kernel::class);
    $artisan->call('down');

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
