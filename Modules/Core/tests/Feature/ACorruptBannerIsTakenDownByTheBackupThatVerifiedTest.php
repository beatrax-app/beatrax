<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Support\BackupSidecar;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Internal\Enums\BackupFailureCause;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Support\SqliteDatabase;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

// The banner says the backups cannot be relied on, and nothing in the tree
// could answer it: on a desktop whose backups folder held six copies, every
// sidecar reading integrity ok and the newest taken after the alert, the
// critical row was still open. The run that verified a copy is the only pass
// that can disprove the claim, so it is the one that takes the row down.

beforeEach(function (): void {
    // On-disk source: VACUUM INTO and the post-copy integrity check both need
    // a real file, and the fixture carries the system_alerts table the rows
    // below are written into once the default connection moves onto it.
    $this->sourcePath = RealSqliteFixture::create('corrupt-banner-source');

    LiveSqliteConnection::pointAt($this->app, $this->sourcePath);

    $this->storageRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-corruptbanner-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage';
    $this->backupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);
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
            if (is_file((string) $entry)) {
                @unlink((string) $entry);
            }
        }
        @rmdir($backupsDir);
        @rmdir(dirname($backupsDir));
        @rmdir(dirname($backupsDir, 2));
    }
});

/**
 * @param  array<string, mixed>|null  $metadata
 * @return int the id of the standing critical row
 */
function corruptBannerRaise(?array $metadata): int
{
    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->create([
        'user_id' => null,
        'dedup_key' => null,
        'kind' => BackupAlertKind::Corrupt->value,
        'severity' => 'critical',
        'message' => 'The backup attempted at 2026-09-10 · 17:10 failed integrity check.',
        'metadata' => $metadata,
    ]);

    return $alert->id;
}

function corruptBannerStillOpen(int $id): bool
{
    return SystemAlert::query()->where('id', $id)->whereNull('acknowledged_at')->exists();
}

// What a previous successful run left behind: a VACUUM of the database exactly
// as it stands, and the sidecar recording that copy's digest. Built by hand
// rather than by a second command run, because that run would withdraw the row
// the skip branch then has to be caught leaving open.
function corruptBannerPriorBackup(mixed $app, string $backupsDir): void
{
    /** @var Filesystem $files */
    $files = $app->make(Filesystem::class);
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    $destination = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-2026-09-10-171000.sqlite';

    /** @var Repository $config */
    $config = $app->make(Repository::class);
    /** @var DatabaseManager $db */
    $db = $app->make(DatabaseManager::class);
    $db->connection(SqliteDatabase::connectionName($config))
        ->statement(sprintf("VACUUM INTO '%s'", $destination));

    /** @var BackupSidecar $sidecar */
    $sidecar = $app->make(BackupSidecar::class);
    $sidecar->write($destination, (string) hash_file('sha256', $destination), '2026-09-10T17:10:00+00:00', '2026-09-10T17:10:01+00:00');
}

it('takes the banner down on the run whose copy passed its integrity check', function (): void {
    $id = corruptBannerRaise(['cause' => BackupFailureCause::SourceUnreadable->value]);

    $this->artisan('db:backup', ['--force' => true])
        ->expectsOutputToContain('Backup written:')
        ->assertSuccessful();

    expect(corruptBannerStillOpen($id))
        ->toBeFalse('A verified backup is the answer to "you cannot rely on your backups", and the banner outlived it.');

    // Resolved, not deleted: the failure still happened, and the row is the
    // only record that it did.
    expect(SystemAlert::query()->where('id', $id)->whereNotNull('acknowledged_at')->count())
        ->toBe(1, 'The row must survive its withdrawal — acknowledging is a stamp, never a delete.');
});

// Written before the cause was recorded at all. The banner reads a row like
// this as the source-integrity sentence, which is precisely the claim a copy
// that verified disproves, so it has to come down with the rest of them.
it('takes down a row raised before the cause was recorded', function (): void {
    $id = corruptBannerRaise(null);

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    expect(corruptBannerStillOpen($id))
        ->toBeFalse('A row with no recorded cause reads as a failed integrity check and must settle like one.');
});

// The one cause that is not a claim about backup health. It records that a
// swap aborted, and its metadata is the only pointer the operator has to the
// snapshot holding the data it aborted over.
it('leaves a failed restore standing, which no backup run speaks to', function (): void {
    $restore = corruptBannerRaise([
        'cause' => BackupFailureCause::RestoreFailed->value,
        'pre_restore_snapshot' => 'pre-restore-2026-09-10-171000.sqlite',
    ]);
    $corrupt = corruptBannerRaise(['cause' => BackupFailureCause::CopySuspect->value]);

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    expect(corruptBannerStillOpen($restore))
        ->toBeTrue('A verified backup says nothing about a restore that aborted, and took its snapshot pointer away.');

    // Raised in the same run, so a withdrawal that closed nothing at all
    // cannot pass this expectation by standing still.
    expect(corruptBannerStillOpen($corrupt))
        ->toBeFalse('The copy-suspect row shares the kind and is answered by the same verified copy.');
});

// The smart-skip branch verified a copy too — it then found an identical one
// already on disk and kept that instead. `backup_overdue` is deliberately not
// withdrawn there, because it counts sidecar dates a skipped run never
// refreshes; soundness is a different claim and this run answered it.
it('settles the banner on the skip branch, where the verified copy was already on disk', function (): void {
    $id = corruptBannerRaise(['cause' => BackupFailureCause::WriteFailed->value]);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    corruptBannerPriorBackup($this->app, $backupsDir);

    $this->artisan('db:backup')
        ->expectsOutputToContain('Skipped')
        ->assertSuccessful();

    expect(corruptBannerStillOpen($id))
        ->toBeFalse('The skipped run ran the same integrity check and kept a copy that matched it.');
});

// The positive control for all of the above: withdrawal is reached only from
// the success exit, so a run that could not produce a backup adds its own row
// and leaves the standing one exactly where it was.
it('withdraws nothing on a run that failed to produce a backup', function (): void {
    $id = corruptBannerRaise(['cause' => BackupFailureCause::SourceUnreadable->value]);

    // Only the staged copy is claimed missing; every other path the command
    // asks about still answers truthfully.
    $this->app->instance(Filesystem::class, new class extends Filesystem
    {
        public function exists($path)
        {
            return str_ends_with((string) $path, '.sqlite.partial') ? false : parent::exists($path);
        }
    });

    $this->artisan('db:backup', ['--force' => true])->assertExitCode(1);

    expect(corruptBannerStillOpen($id))
        ->toBeTrue('A failed run disproves nothing, and this one took the standing banner down.');

    expect(SystemAlert::query()->where('kind', BackupAlertKind::Corrupt->value)->whereNull('acknowledged_at')->count())
        ->toBe(2, 'The failed run records its own failure beside the one already standing.');
});
