<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    // The command's VACUUM INTO needs a real on-disk DB, so the named `sqlite`
    // connection is rebound here; RefreshDatabase keeps its own :memory: one.
    $this->livePath = RealSqliteFixture::create('restore-test-live');

    LiveSqliteConnection::pointAt($this->app, $this->livePath);

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-restore-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));

    // The command reads UserDataPathService::framework('down'), so the test
    // writes that exact file rather than running `php artisan down`, which
    // targets the framework's own un-redirected storage path.
    $this->downMarker = (new UserDataPathService)->framework('down');

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $files->ensureDirectoryExists(dirname($this->downMarker));

    $this->markDown = function () use ($files): void {
        $files->put($this->downMarker, '');
    };
    $this->markUp = function () use ($files): void {
        if ($files->exists($this->downMarker)) {
            $files->delete($this->downMarker);
        }
    };

    // Start every test "up" so the not-in-maintenance refusal is deterministic.
    ($this->markUp)();
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);
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

    // Truncate to 100 bytes: the SQLite header is left incomplete, so
    // PRAGMA integrity_check refuses.
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
        // Non-TTY harness: the command must fail rather than hang on stdin.
        $this->artisan('db:restore', ['path' => $sourcePath])
            ->expectsOutputToContain('--confirm')
            ->assertExitCode(1);

        expect((string) file_get_contents($livePath))->toBe($liveContentsBefore);
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});

// These two raise BackupNotSupportedException rather than returning FAILURE:
// no retry helps and nothing on the machine needs fixing, so the operator is
// pointed at configuration rather than at the disk or the file they handed in.

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
