<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Modules\Core\Public\Contracts\Clock;
use Tests\Helpers\RealSqliteFixture;

// A backup on disk with no sidecar is worse than no backup: the next run's
// smart-skip reads the missing sidecar as "no recent backup exists" and
// silently re-writes, so the failure has to surface rather than be swallowed.

beforeEach(function (): void {
    // Frozen: both tests pre-occupy a path the command derives from the clock
    // at seconds resolution. Unfrozen, test and command can land in different
    // seconds, the obstruction misses, and the assertion fails spuriously.
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');

    $this->sourcePath = RealSqliteFixture::create('backup-sidecar-source');

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow(null);
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    if (is_dir($backupsDir)) {
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            is_dir((string) $entry) ? @rmdir((string) $entry) : @unlink((string) $entry);
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});

// The basename the command will choose on this run — timestamp from the
// injected Clock, so the sidecar path can be occupied before it gets there.
function sidecarBasename(mixed $app): string
{
    /** @var Clock $clock */
    $clock = $app->make(Clock::class);

    return 'beatrax-'.$clock->now()->format('Y-m-d-His').'.sqlite';
}

// file_put_contents() refuses a path that is already a directory, which is the
// one sidecar failure reachable without a filesystem that lies about writes.
it('records a critical alert and fails when the sidecar cannot be written', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    (new Filesystem)->ensureDirectoryExists($backupsDir);

    $basename = sidecarBasename($this->app);
    mkdir($backupsDir.DIRECTORY_SEPARATOR.$basename.'.meta.json.tmp');

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    // The backup itself succeeded — that is what makes the missing sidecar bad.
    expect(is_file($backupsDir.DIRECTORY_SEPARATOR.$basename))->toBeTrue()
        ->and(is_file($backupsDir.DIRECTORY_SEPARATOR.$basename.'.meta.json'))->toBeFalse();

    $alert = DB::table('system_alerts')
        ->where('kind', 'backup_corrupt')
        ->where('severity', 'critical')
        ->first();

    expect($alert)->not->toBeNull();
    expect((string) $alert->metadata)->toContain('sidecar_write');
});

// rename() onto a non-empty directory fails, reaching the last of the three
// guards — content written correctly, only the move into place failed.
it('records a critical alert when the sidecar cannot be renamed into place', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    (new Filesystem)->ensureDirectoryExists($backupsDir);

    $basename = sidecarBasename($this->app);
    $sidecar = $backupsDir.DIRECTORY_SEPARATOR.$basename.'.meta.json';
    mkdir($sidecar);
    file_put_contents($sidecar.DIRECTORY_SEPARATOR.'occupied', 'x');

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    $alert = DB::table('system_alerts')
        ->where('kind', 'backup_corrupt')
        ->where('severity', 'critical')
        ->first();

    expect($alert)->not->toBeNull();

    // The temp file is cleaned up on the failing path too, so a retry is clean.
    expect(is_file($sidecar.'.tmp'))->toBeFalse();

    @unlink($sidecar.DIRECTORY_SEPARATOR.'occupied');
    @rmdir($sidecar);
});
