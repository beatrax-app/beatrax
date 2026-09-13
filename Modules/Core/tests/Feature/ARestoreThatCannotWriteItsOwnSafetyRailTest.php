<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Backup\LiveDatabaseTransplant;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    $this->livePath = RealSqliteFixture::create('restore-rail-live');
    LiveSqliteConnection::pointAt($this->app, $this->livePath);

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-restore-rail-'.bin2hex(random_bytes(8));
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $this->downMarker = (new UserDataPathService)->framework('down');
    $files->ensureDirectoryExists(dirname($this->downMarker));
    $files->put($this->downMarker, '');
});

afterEach(function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    @chmod($backupsDir, 0o700);

    LiveSqliteConnection::restore($this->app);
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $livePath */
    $livePath = $this->livePath;
    RealSqliteFixture::cleanup($livePath);

    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    exec('rm -rf '.escapeshellarg($storageRoot));
});

// The pre-restore snapshot IS the undo. A restore writes three full-sized
// copies of the database, so the step most likely to meet a full disk is the
// one the operator's only way back is written by — and a raw query exception
// off it puts the statement on a console instead of the refusal every other
// failure here is spelled with.
it('refuses in its own words when the pre-restore snapshot cannot be written', function (): void {
    $sourcePath = RealSqliteFixture::create('restore-rail-source');

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $files->ensureDirectoryExists($backupsDir);
    chmod($backupsDir, 0o500);

    /** @var string $livePath */
    $livePath = $this->livePath;
    $liveBefore = (string) file_get_contents($livePath);

    try {
        $this->artisan('db:restore', [
            'path' => $sourcePath,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('Restore refused')
            ->assertExitCode(1);

        expect((string) file_get_contents($livePath))->toBe($liveBefore, 'The live database must be untouched by a refusal.');
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});

// db:restore catches exactly BackupIoException around the swap, and that catch
// is the whole of "maintenance mode outlives a failure, and the operator is
// told where their undo is". The transplant's own docblock promises it.
it('answers a live database SQLite cannot open with the exception the restore is written to catch', function (): void {
    $sourcePath = RealSqliteFixture::create('restore-rail-transplant-source');
    $unreachable = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-no-such-dir-'.bin2hex(random_bytes(6)).DIRECTORY_SEPARATOR.'live.sqlite';

    /** @var LiveDatabaseTransplant $transplant */
    $transplant = $this->app->make(LiveDatabaseTransplant::class);

    try {
        expect(fn () => $transplant($sourcePath, $unreachable, '/tmp/pre-restore-undo.sqlite'))
            ->toThrow(BackupIoException::class, '/tmp/pre-restore-undo.sqlite');
    } finally {
        RealSqliteFixture::cleanup($sourcePath);
    }
});

// The one failure the command is built to survive: the pages would not go in,
// the live database is whatever the half-written swap left, and the whole of
// the operator's way back is the snapshot path on the console plus a
// maintenance mode that outlives the command.
it('leaves maintenance mode on and names the snapshot when the swap itself fails', function (): void {
    $sourcePath = RealSqliteFixture::create('restore-rail-swap-source');

    /** @var string $livePath */
    $livePath = $this->livePath;

    // Opened before the live file loses its write bit, so the connection the
    // snapshot is vacuumed out of is already up: the failure under test is the
    // swap, not the rail ahead of it.
    $this->app->make('db')->connection('sqlite')->scalar('PRAGMA integrity_check');
    chmod($livePath, 0o444);

    try {
        $this->artisan('db:restore', [
            'path' => $sourcePath,
            '--confirm' => true,
        ])
            ->expectsOutputToContain('Restore failed mid-swap. Pre-restore snapshot at ')
            ->assertExitCode(1);

        /** @var Filesystem $files */
        $files = $this->app->make(Filesystem::class);
        /** @var string $downMarker */
        $downMarker = $this->downMarker;
        expect($files->exists($downMarker))->toBeTrue('Maintenance mode must outlive a failure past the point the live file is touched.');
    } finally {
        chmod($livePath, 0o644);
        RealSqliteFixture::cleanup($sourcePath);
    }
});
