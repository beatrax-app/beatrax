<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Tests\Helpers\RealSqliteFixture;

/*
 * The two guards between a successful VACUUM INTO and a usable backup.
 *
 * VACUUM INTO can return without throwing and still leave nothing on disk,
 * and the file it writes is created by SQLite via open(2) — outside PHP's
 * umask — so it has to be narrowed to 0600 before it can be kept. Both
 * failures end in a critical alert and a non-zero exit, and the chmod one
 * additionally deletes the file: a backup of the whole database that might be
 * group- or world-readable is worse than no backup at all.
 *
 * Neither is reachable by arranging the filesystem, because both are about the
 * filesystem lying. The command takes its Filesystem by injection, so the test
 * substitutes one that reports the failure.
 */
beforeEach(function (): void {
    // Frozen for the VACUUM-refusal case below, which occupies the exact path
    // the command is about to choose. That path carries a seconds-resolution
    // timestamp, so an unfrozen clock lets the test and the command land in
    // different seconds on a slow runner — the obstruction misses and the
    // assertion fails for an unrelated reason.
    CarbonImmutable::setTestNow('2026-07-29 12:00:00');

    $this->sourcePath = RealSqliteFixture::create('backup-guards-source');

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

it('alerts and fails when VACUUM INTO reports success but writes nothing', function (): void {
    // Only the .sqlite output is claimed missing; every other path the command
    // asks about still answers truthfully, so the branch under test is the
    // only thing that changes.
    $this->app->instance(Filesystem::class, new class extends Filesystem
    {
        public function exists($path)
        {
            return str_ends_with((string) $path, '.sqlite') ? false : parent::exists($path);
        }
    });

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->first();
    expect($alert)->not->toBeNull()
        ->and($alert->severity)->toBe('critical')
        ->and(json_encode($alert->metadata))->toContain('no output file produced');
});

// The file is deleted rather than kept, because a backup of the entire
// database whose permissions cannot be confirmed is a worse outcome than not
// having one — and the alert's suspect path is null for the same reason.
it('deletes the backup and alerts when it cannot be narrowed to 0600', function (): void {
    $this->app->instance(Filesystem::class, new class extends Filesystem
    {
        public function chmod($path, $mode = null)
        {
            return $mode === null ? parent::chmod($path) : false;
        }
    });

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    expect(glob($backupsDir.DIRECTORY_SEPARATOR.'*.sqlite'))->toBe([]);

    $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->first();
    expect($alert)->not->toBeNull()
        ->and(json_encode($alert->metadata))->toContain('chmod');
});

// The corrupt-source tests never reach this branch: a truncated database
// fails PRAGMA data_version first, which is an earlier catch with its own
// phase. Getting here needs a source SQLite is happy to read and a
// destination it refuses to write — VACUUM INTO will not write over an
// existing path, so occupying the one the command is about to choose does it.
it('alerts and preserves the output as .suspect when VACUUM INTO refuses', function (): void {
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);
    $basename = 'beatrax-'.$clock->now()->format('Y-m-d-His').'.sqlite';

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    (new Filesystem)->ensureDirectoryExists($backupsDir);
    file_put_contents($backupsDir.DIRECTORY_SEPARATOR.$basename, 'already here');

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    $alert = SystemAlert::query()->where('kind', 'backup_corrupt')->first();
    expect($alert)->not->toBeNull()
        ->and($alert->severity)->toBe('critical')
        ->and(json_encode($alert->metadata))->toContain('vacuum_into');

    // The file that was in the way is moved aside rather than deleted, so
    // whatever it was can still be inspected.
    expect(is_file($backupsDir.DIRECTORY_SEPARATOR.$basename.'.suspect'))->toBeTrue()
        ->and(is_file($backupsDir.DIRECTORY_SEPARATOR.$basename))->toBeFalse();
});
