<?php

declare(strict_types=1);

use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('backup-corrupt-source');

    // Truncate the source DB to 100 bytes. The SQLite header is 100
    // bytes too, so this strips the sqlite_master page that VACUUM INTO
    // needs to enumerate user tables.
    file_put_contents($this->sourcePath, substr((string) file_get_contents($this->sourcePath), 0, 100));

    LiveSqliteConnection::pointAt($this->app, $this->sourcePath);

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);
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

// The live database IS the one the command was asked to back up, so on this
// path the alert row cannot be written: it would go into the file just found
// unreadable. The console line and the exit code are the report that survives,
// and BackupPostVacuumGuardsTest keeps the alert covered on the paths where the
// source is sound and only the output is not.
it('exits non-zero and names the corruption when the live database is unreadable', function (): void {
    $this->artisan('db:backup', ['--force' => true])
        ->expectsOutputToContain('integrity check')
        ->assertFailed();
});

it('leaves no clean backup file behind when the source is unreadable', function (): void {
    $this->artisan('db:backup', ['--force' => true])->assertFailed();

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    if (! is_dir($backupsDir)) {
        return;
    }

    expect((array) glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite'))
        ->toBe([], 'A corrupt source must never leave a clean .sqlite behind.');
});
