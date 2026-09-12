<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Support\BackupSidecar;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

// Without --force the command decides whether to keep the copy it just made by
// comparing its digest against the newest sidecar's. Every way that read can go
// wrong has to mean "do not skip": an unnecessary backup costs disk, whereas
// skipping on a sidecar it failed to parse believes in a backup nobody wrote.
beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('backup-skip-source');

    LiveSqliteConnection::pointAt($this->app, $this->sourcePath);

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-test-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
    (new Filesystem)->ensureDirectoryExists($this->backupsDir);
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
        foreach ((array) glob($backupsDir.DIRECTORY_SEPARATOR.'*') as $entry) {
            is_dir((string) $entry) ? @rmdir((string) $entry) : @unlink((string) $entry);
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});

/**
 * @return list<string>
 */
function skipProducedBackups(string $dir): array
{
    return array_values(array_map(strval(...), (array) glob($dir.DIRECTORY_SEPARATOR.'*.sqlite')));
}

it('does not skip when there is nothing to compare against', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toHaveCount(1);
});

// A directory where a sidecar should be is not a hypothetical: the sidecar is
// written to a .tmp sibling and renamed, so an interrupted run can leave odd
// entries behind that still match the glob.
it('does not skip when the newest sidecar is not a file', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    // The copy is there, so the only thing wrong is the sidecar's shape.
    file_put_contents($backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite', 'seeded');
    mkdir($backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite.meta.json');

    $this->artisan('db:backup')->assertExitCode(0);

    // The seeded copy and the fresh one: the run wrote a backup rather than
    // believing a sidecar it could not read.
    expect(skipProducedBackups($backupsDir))->toHaveCount(2);

    @rmdir($backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite.meta.json');
});

it('does not skip when the newest sidecar does not hold an object', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    // The copy is there, so the only thing wrong is what the sidecar holds.
    file_put_contents($backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite', 'seeded');
    file_put_contents(
        $backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-01-01-000000.sqlite.meta.json',
        '"a bare string, not the object this expects"',
    );

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toHaveCount(2);
});

it('does not skip a second invocation once rows have been committed since the last backup', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;

    $this->artisan('db:backup')->assertExitCode(0);
    expect(skipProducedBackups($backupsDir))->toHaveCount(1);

    // A separate connection, as the running app is: the whole question the
    // skip answers is whether somebody else's commits have landed since.
    $writer = new PDO('sqlite:'.$sourcePath, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    for ($i = 0; $i < 5; $i++) {
        $writer->exec('INSERT INTO transactions (user_id, amount_minor, currency, booked_at)
                       VALUES (1, '.(100 + $i).", 'EUR', '2026-08-25 00:00:00')");
    }
    unset($writer);

    // Timestamps are the coarsest part of the signature, so a second-boundary
    // crossing keeps this about the data rather than about clock resolution.
    sleep(1);

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toHaveCount(2);
});

// The one way the read can go wrong that this file did not cover: the sidecar
// parses perfectly and the copy it vouches for is not there. The retention
// sweep unlinks the copy before the sidecar, so an interrupted run leaves
// exactly this, and believing it deletes the verified copy just made.
it('does not skip when the newest sidecar names a backup that is gone', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;

    $this->artisan('db:backup')->assertExitCode(0);
    $first = skipProducedBackups($backupsDir);
    expect($first)->toHaveCount(1);

    // The copy alone, as a sweep that stopped between the two unlinks leaves
    // it. Its sidecar carries the digest of a database nothing has written to
    // since, so the next run's own digest matches it exactly.
    unlink($first[0]);
    expect(skipProducedBackups($backupsDir))->toBe([]);

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toHaveCount(1);
});

// The positive control for the walk: an orphan must not turn every later run
// into a forced backup, only stop the orphan itself from answering.
it('still skips on an older sidecar whose copy survived the sweep', function (): void {
    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;

    $this->artisan('db:backup')->assertExitCode(0);
    $first = skipProducedBackups($backupsDir);
    expect($first)->toHaveCount(1);

    $survivor = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-04-22-030000.sqlite';
    copy($first[0], $survivor);
    copy($first[0].BackupSidecar::SUFFIX, $survivor.BackupSidecar::SUFFIX);

    unlink($first[0]);

    $this->artisan('db:backup')->assertExitCode(0);

    expect(skipProducedBackups($backupsDir))->toBe([$survivor]);
});
