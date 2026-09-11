<?php

declare(strict_types=1);

use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Core\Internal\Backup\BackupFreshness;
use Modules\Core\Internal\Console\Probes\BootProbeState;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Internal\Listeners\HealthCheckListener;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Services\SystemAlertWriter;
use Psr\Log\LoggerInterface;
use Tests\Helpers\LiveSqliteConnection;
use Tests\Helpers\RealSqliteFixture;

uses(RefreshDatabase::class);

// `backup_overdue` has banner copy in all 26 locales, a rendering case in
// system-alert-message, and a test forbidding it from naming a terminal command
// -- because neither shipped bundle has one. Its only raiser was
// BackupFreshnessProbe, which runs only from `beatrax:doctor`, which is a
// terminal command and is not scheduled. Nothing in a shipped build could raise
// it, and a verified backup landing did not take it down either.

function bnrBackupsDir(): string
{
    /** @var string $dir */
    $dir = test()->bnrBackupsDir;

    return $dir;
}

function bnrSidecar(Filesystem $files, int $hoursAgo): void
{
    /** @var Clock $clock */
    $clock = app(Clock::class);
    $at = $clock->now()->subHours($hoursAgo);

    $files->makeDirectory(bnrBackupsDir(), 0o755, recursive: true, force: true);
    $files->put(
        bnrBackupsDir().DIRECTORY_SEPARATOR.'beatrax-'.$at->format('Y-m-d-His').'.sqlite.meta.json',
        (string) json_encode([
            'data_version' => 1,
            'started_at' => $at->subSecond()->toIso8601String(),
            'completed_at' => $at->toIso8601String(),
            'integrity' => 'ok',
        ], JSON_THROW_ON_ERROR),
    );
}

function bnrBoot(): void
{
    /** @var DatabaseManager $db */
    $db = app(DatabaseManager::class);

    $listener = new HealthCheckListener(
        new BootProbeState,
        app(Clock::class),
        app(LoggerInterface::class),
        $db,
        app(SystemAlertWriter::class),
        app(BackupFreshness::class),
    );

    $listener(new ConnectionEstablished($db->connection()));
}

function bnrOverdueRows(): int
{
    return SystemAlert::query()
        ->where('kind', BackupAlertKind::Overdue->value)
        ->whereNull('acknowledged_at')
        ->count();
}

beforeEach(function (): void {
    // VACUUM INTO needs real tables to copy, so the `sqlite` connection the
    // command reads moves to an on-disk file while `sqlite_testing` stays the
    // in-memory default RefreshDatabase and SystemAlert::create() work against.
    $this->sourcePath = RealSqliteFixture::create('banner-nothing-raised');
    LiveSqliteConnection::pointAt($this->app, $this->sourcePath);

    $this->storageRoot = sys_get_temp_dir()
        .DIRECTORY_SEPARATOR.'beatrax-bnr-'.bin2hex(random_bytes(8))
        .DIRECTORY_SEPARATOR.'storage';
    $this->bnrBackupsDir = $this->storageRoot.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.$this->storageRoot);

    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    $this->files = $files;
});

afterEach(function (): void {
    LiveSqliteConnection::restore($this->app);
    putenv('NATIVEPHP_STORAGE_PATH');

    /** @var string $sourcePath */
    $sourcePath = $this->sourcePath;
    RealSqliteFixture::cleanup($sourcePath);

    /** @var Filesystem $files */
    $files = $this->files;
    /** @var string $storageRoot */
    $storageRoot = $this->storageRoot;
    $files->deleteDirectory(dirname($storageRoot));
});

it('raises the overdue banner when the app opens and the newest backup is stale', function (): void {
    /** @var Filesystem $files */
    $files = $this->files;
    bnrSidecar($files, BackupFreshness::STALE_AFTER_HOURS + 12);

    bnrBoot();

    expect(bnrOverdueRows())->toBe(1);

    /** @var SystemAlert $alert */
    $alert = SystemAlert::query()->where('kind', BackupAlertKind::Overdue->value)->firstOrFail();
    expect($alert->severity)->toBe(SystemAlertSeverity::Warning->value)
        ->and($alert->user_id)->toBeNull();
});

// The positive control. A boot that raised the banner whatever it found would
// put it in front of every reader on every launch.
it('raises nothing when the newest backup is inside the window', function (): void {
    /** @var Filesystem $files */
    $files = $this->files;
    bnrSidecar($files, 2);

    bnrBoot();

    expect(bnrOverdueRows())->toBe(0);
});

// An install that has never backed up is a different fault, and boot cannot
// tell a first run from a broken one. The doctor still reports it.
it('raises nothing on an install that has never backed up', function (): void {
    bnrBoot();

    expect(bnrOverdueRows())->toBe(0);
});

it('raises it once across a restart storm', function (): void {
    /** @var Filesystem $files */
    $files = $this->files;
    bnrSidecar($files, BackupFreshness::STALE_AFTER_HOURS + 12);

    bnrBoot();
    bnrBoot();
    bnrBoot();

    expect(bnrOverdueRows())->toBe(1);
});

it('takes the banner down when a verified backup lands', function (): void {
    /** @var Filesystem $files */
    $files = $this->files;
    bnrSidecar($files, BackupFreshness::STALE_AFTER_HOURS + 12);

    bnrBoot();
    expect(bnrOverdueRows())->toBe(1);

    test()->artisan('db:backup --force')->assertExitCode(0);

    expect(bnrOverdueRows())->toBe(0);
});
