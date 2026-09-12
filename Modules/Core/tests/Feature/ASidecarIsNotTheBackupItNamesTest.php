<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Backup\BackupFreshness;
use Modules\Core\Internal\Console\Support\BackupSidecar;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemAlertWriter;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

// The backups folder holds two kinds of file and only one of them is a backup.
// Every reader of the `.meta.json` treated it as proof that the `.sqlite` beside
// it exists, and nothing ever looked. The retention sweep unlinks the copy
// first, so a run that stops between the two unlinks manufactures the state.

beforeEach(function (): void {
    $this->sourcePath = RealSqliteFixture::create('sidecar-not-the-backup');
    LiveSqliteConnection::pointAt($this->app, $this->sourcePath);

    $this->storageRoot = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR.'beatrax-snb-'.bin2hex(random_bytes(8))
        .DIRECTORY_SEPARATOR.'storage';
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

    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    (new Filesystem)->deleteDirectory(dirname($storageRoot));
});

function snbDir(): string
{
    /** @var string $dir */
    $dir = test()->backupsDir;

    return $dir;
}

function snbWriteSidecar(string $stamp, string $completedAt, bool $withCopy): string
{
    $backup = snbDir().DIRECTORY_SEPARATOR.'beatrax-'.$stamp.'.sqlite';

    if ($withCopy) {
        file_put_contents($backup, 'the copy this sidecar names');
    }

    file_put_contents($backup.BackupSidecar::SUFFIX, (string) json_encode([
        'content_sha256' => 'irrelevant-to-freshness',
        'started_at' => $completedAt,
        'completed_at' => $completedAt,
        'integrity' => 'ok',
    ], JSON_THROW_ON_ERROR));

    return $backup;
}

function snbFreshness(): BackupFreshness
{
    return new BackupFreshness(
        new Filesystem,
        app(Clock::class),
        app(DatabaseManager::class),
        new UserDataPathService,
        app(SystemAlertWriter::class),
    );
}

/**
 * @return list<string>
 */
function snbBackups(): array
{
    return array_values(array_map(strval(...), (array) glob(snbDir().DIRECTORY_SEPARATOR.'*.sqlite')));
}

it('reports no verified backup when the only sidecar names a copy that is gone', function (): void {
    /** @var Clock $clock */
    $clock = app(Clock::class);
    snbWriteSidecar('2026-09-11-030000', $clock->now()->subMinutes(10)->toIso8601String(), withCopy: false);

    // Ten minutes old and reading as current is the whole fault: the doctor
    // stayed green over a folder holding no backup at all.
    expect(snbFreshness()->newestVerifiedAt())->toBeNull();
});

it('dates freshness from the newest sidecar whose copy is still there', function (): void {
    /** @var Clock $clock */
    $clock = app(Clock::class);
    $survivor = $clock->now()->subHours(30);

    snbWriteSidecar('2026-09-10-030000', $survivor->toIso8601String(), withCopy: true);
    snbWriteSidecar('2026-09-11-030000', $clock->now()->subMinutes(10)->toIso8601String(), withCopy: false);

    $newest = snbFreshness()->newestVerifiedAt();

    expect($newest)->not->toBeNull()
        ->and($newest?->toIso8601String())->toBe($survivor->toIso8601String());
});

it('never lets a sidecar naming a vanished copy stand in for a backup that is there', function (): void {
    /** @var Clock $clock */
    $clock = app(Clock::class);
    snbWriteSidecar('2026-09-11-030000', $clock->now()->toIso8601String(), withCopy: false);

    expect(BackupSidecar::describesAPresentBackup(
        snbDir().DIRECTORY_SEPARATOR.'beatrax-2026-09-11-030000.sqlite'.BackupSidecar::SUFFIX,
    ))->toBeFalse();
});

// A run interrupted between the two unlinks has to leave a backup nothing
// vouches for -- the next run remakes it -- rather than a voucher for nothing,
// which licenses a skip that takes the only remaining copy with it.
it('removes a pruned backup\'s sidecar before the copy it names', function (): void {
    $recorder = new class extends Filesystem
    {
        /** @var list<string> */
        public array $deleted = [];

        public function delete($paths)
        {
            foreach (is_array($paths) ? $paths : func_get_args() as $path) {
                $this->deleted[] = basename((string) $path);
            }

            return parent::delete($paths);
        }
    };

    $this->app->instance(Filesystem::class, $recorder);

    // Eight dailies, none of them a Sunday, so the weekly rescue arm cannot
    // change which of them the sweep is asked to remove.
    foreach (['2026-04-22-030000', '2026-04-23-030000', '2026-04-24-030000', '2026-04-25-030000',
        '2026-04-27-030000', '2026-04-28-030000', '2026-04-29-030000', '2026-04-30-030000'] as $stamp) {
        snbWriteSidecar($stamp, '2026-04-30T03:00:00+00:00', withCopy: true);
    }

    $this->artisan('db:backup', ['--force' => true])->assertSuccessful();

    $prunedCopy = 'beatrax-2026-04-22-030000.sqlite';
    $prunedSidecar = $prunedCopy.BackupSidecar::SUFFIX;

    expect($recorder->deleted)->toContain($prunedCopy)
        ->and($recorder->deleted)->toContain($prunedSidecar);

    $copyAt = (int) array_search($prunedCopy, $recorder->deleted, true);
    $sidecarAt = (int) array_search($prunedSidecar, $recorder->deleted, true);

    expect($sidecarAt)->toBeLessThan($copyAt);

    // Nine dailies in, seven kept: the sweep really did run over this folder.
    expect(snbBackups())->toHaveCount(7);
});

// The question is asked of whatever the directory walk hands it, and the walk
// sees the backups themselves. A path that is not a sidecar names no copy, so
// it is answered rather than measured — `substr` off a name without the suffix
// would cut a real path short and ask about a file nobody wrote.
it('answers no for a path that is not a sidecar at all', function (): void {
    $copy = snbWriteSidecar('2026-09-11-030000', '2026-09-11T03:00:00+00:00', withCopy: true);

    expect(BackupSidecar::describesAPresentBackup($copy))->toBeFalse()
        ->and(BackupSidecar::describesAPresentBackup($copy.BackupSidecar::SUFFIX))->toBeTrue();
});
