<?php

declare(strict_types=1);

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Probes\BackupFreshnessProbe;
use Modules\Core\Internal\Console\Probes\ComposerVersionProbe;
use Modules\Core\Internal\Console\Probes\ExternalToolVersionProbe;
use Modules\Core\Internal\Console\Probes\NodeVersionProbe;
use Modules\Core\Internal\Console\Probes\PhpVersionProbe;
use Modules\Core\Internal\Console\Probes\Probe;
use Modules\Core\Internal\Console\Probes\ProbeResult;
use Modules\Core\Internal\Console\Probes\SqliteCliVersionProbe;
use Modules\Core\Internal\Console\Probes\SynchronousModeProbe;
use Modules\Core\Internal\Console\Probes\WalModeProbe;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\SystemClock;
use Modules\Core\Public\Services\UserDataPathService;
use Tests\Helpers\RealSqliteFixture;

beforeEach(function (): void {
    // On-disk fixture: the :memory: sqlite_testing connection cannot express WAL,
    // so the journal_mode and synchronous probes would read an impossible value.
    $this->sourcePath = RealSqliteFixture::create('doctor-probe-source', [
        'CREATE TABLE transactions (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NULL,
            amount_minor INTEGER NOT NULL,
            currency TEXT NOT NULL,
            booked_at TEXT NOT NULL
        )',
        'CREATE TABLE system_alerts (
            id INTEGER PRIMARY KEY,
            user_id INTEGER NULL,
            kind TEXT NOT NULL,
            severity TEXT NOT NULL,
            message TEXT NOT NULL,
            metadata TEXT NULL,
            created_at TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
            acknowledged_at TEXT NULL
        )',
    ]);

    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', $this->sourcePath);

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    // The probes resolve $this->db->connection() from the framework default,
    // so it has to point at the on-disk file rather than sqlite_testing.
    $config->set('database.default', 'sqlite');

    $this->backupsDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'beatrax-probe-'.bin2hex(random_bytes(8)).DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app'.DIRECTORY_SEPARATOR.'backups';
    putenv('NATIVEPHP_STORAGE_PATH='.dirname($this->backupsDir, 2));
});

afterEach(function (): void {
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

it('Probe contract declares label() and run() with the documented signatures', function (): void {
    $reflection = new ReflectionClass(Probe::class);
    expect($reflection->isInterface())->toBeTrue();
    expect($reflection->hasMethod('label'))->toBeTrue();
    expect($reflection->hasMethod('run'))->toBeTrue();

    $label = $reflection->getMethod('label');
    expect((string) $label->getReturnType())->toBe('string');

    $run = $reflection->getMethod('run');
    expect((string) $run->getReturnType())->toBe(ProbeResult::class);
});

it('ProbeResult is a final readonly value object with severity/message/metadata', function (): void {
    $result = new ProbeResult('ok', 'all good', ['k' => 1]);
    $reflection = new ReflectionClass(ProbeResult::class);

    expect($reflection->isFinal())->toBeTrue();
    expect($reflection->isReadOnly())->toBeTrue();
    expect($result->severity)->toBe('ok');
    expect($result->message)->toBe('all good');
    expect($result->metadata)->toBe(['k' => 1]);
});

it('WalModeProbe returns ok when journal_mode is WAL', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    // The fixture already set journal_mode = WAL; no arrange step needed.
    $probe = new WalModeProbe($db);
    expect($probe->label())->toBe('SQLite WAL mode');

    $result = $probe->run();

    expect($result->severity)->toBe('ok');
    expect($result->message)->toContain('WAL');
});

it('WalModeProbe returns warning when journal_mode drifted off WAL', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection('sqlite')->statement('PRAGMA journal_mode = DELETE');

    $probe = new WalModeProbe($db);
    $result = $probe->run();

    expect($result->severity)->toBe('warning');
    expect($result->message)->toContain('delete');
    expect($result->metadata)->toHaveKey('current_mode');
});

it('SynchronousModeProbe returns ok when synchronous = 1 (NORMAL)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection('sqlite')->statement('PRAGMA synchronous = NORMAL');

    $probe = new SynchronousModeProbe($db);
    expect($probe->label())->toBe('SQLite synchronous mode');

    $result = $probe->run();

    expect($result->severity)->toBe('ok');
});

it('SynchronousModeProbe returns warning when synchronous is FULL (2)', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->connection('sqlite')->statement('PRAGMA synchronous = FULL');

    $probe = new SynchronousModeProbe($db);
    $result = $probe->run();

    expect($result->severity)->toBe('warning');
    expect($result->message)->toContain('2');
    expect($result->metadata)->toHaveKey('current_level');
});

it('BackupFreshnessProbe returns warning AND writes a system_alerts row when no sidecars exist', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    $probe = new BackupFreshnessProbe($files, $clock, $db, new UserDataPathService);
    expect($probe->label())->toBe('Backup freshness');

    $result = $probe->run();

    expect($result->severity)->toBe('warning');
    expect($result->message)->toContain('backup');

    $alerts = SystemAlert::query()->where('kind', 'backup_overdue')->get();
    expect($alerts)->toHaveCount(1);

    /** @var SystemAlert $alert */
    $alert = $alerts->first();
    expect($alert->severity)->toBe('warning');
});

it('BackupFreshnessProbe returns ok and does NOT write an alert when a fresh sidecar exists', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    $tenMinutesAgo = $clock->now()->subMinutes(10);
    $sidecar = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$tenMinutesAgo->format('Y-m-d-His').'.sqlite.meta.json';
    $files->put($sidecar, (string) json_encode([
        'data_version' => 1,
        'started_at' => $tenMinutesAgo->subSecond()->toIso8601String(),
        'completed_at' => $tenMinutesAgo->toIso8601String(),
        'integrity' => 'ok',
    ]));

    $probe = new BackupFreshnessProbe($files, $clock, $db, new UserDataPathService);
    $result = $probe->run();

    expect($result->severity)->toBe('ok');

    expect(SystemAlert::query()->where('kind', 'backup_overdue')->count())->toBe(0);
});

// CarbonImmutable::parse() answers NOW for a blank string instead of throwing,
// so a sidecar carrying a whitespace-only completed_at read as a backup that
// finished this second — the probe said "0h old", stayed green, and wrote no
// alert. A backup that never completed looking current is a lie the one
// morning it matters, so the blank must reach the caller as "no backup".
it('BackupFreshnessProbe never reads a blank completed_at as a backup finished right now', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    $sidecar = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-blank.sqlite.meta.json';
    $files->put($sidecar, (string) json_encode([
        'data_version' => 1,
        'started_at' => $clock->now()->subHour()->toIso8601String(),
        'completed_at' => '   ',
        'integrity' => 'ok',
    ]));

    $result = (new BackupFreshnessProbe($files, $clock, $db, new UserDataPathService))->run();

    expect($result->severity)->toBe('warning');
    expect($result->metadata)->toHaveKey('hours_old');
    expect($result->metadata['hours_old'])->toBeNull();
    expect(SystemAlert::query()->where('kind', 'backup_overdue')->count())->toBe(1);
});

it('BackupFreshnessProbe returns warning AND writes an alert when newest sidecar is older than 48h', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    $sixtyHoursAgo = $clock->now()->subHours(60);
    $sidecar = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$sixtyHoursAgo->format('Y-m-d-His').'.sqlite.meta.json';
    $files->put($sidecar, (string) json_encode([
        'data_version' => 1,
        'started_at' => $sixtyHoursAgo->subSecond()->toIso8601String(),
        'completed_at' => $sixtyHoursAgo->toIso8601String(),
        'integrity' => 'ok',
    ]));

    $probe = new BackupFreshnessProbe($files, $clock, $db, new UserDataPathService);
    $result = $probe->run();

    expect($result->severity)->toBe('warning');
    expect($result->metadata)->toHaveKey('hours_old');

    expect(SystemAlert::query()->where('kind', 'backup_overdue')->count())->toBe(1);
});

it('BackupFreshnessProbe suppresses a second alert row within the 1-hour recency window', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Filesystem $files */
    $files = $this->app->make(Filesystem::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);

    /** @var string $backupsDir */
    $backupsDir = $this->backupsDir;
    $files->makeDirectory($backupsDir, 0o755, recursive: true, force: true);

    $probe = new BackupFreshnessProbe($files, $clock, $db, new UserDataPathService);

    $probe->run();
    // Repeat runs must be no-ops on the audit trail: the banner renders one
    // card per row, so 100 doctor invocations would stack 100 identical cards.
    $probe->run();
    $probe->run();

    expect(SystemAlert::query()->where('kind', 'backup_overdue')->count())
        ->toBe(1, 'Repeated probe runs within the recency window must not add new alert rows.');
});

it('WalModeProbe never throws — IO failure is captured as a critical ProbeResult', function (): void {
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', '/nonexistent/path/to/beatrax-missing.sqlite');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $probe = new WalModeProbe($db);
    $result = $probe->run();

    expect($result->severity)->toBe('critical');
    expect($result->message)->not->toBe('');
});

it('binds WalModeProbe, SynchronousModeProbe, and BackupFreshnessProbe through the container with plain constructor injection', function (): void {
    $wal = $this->app->make(WalModeProbe::class);
    expect($wal)->toBeInstanceOf(WalModeProbe::class);

    $sync = $this->app->make(SynchronousModeProbe::class);
    expect($sync)->toBeInstanceOf(SynchronousModeProbe::class);

    $fresh = $this->app->make(BackupFreshnessProbe::class);
    expect($fresh)->toBeInstanceOf(BackupFreshnessProbe::class);

    expect($this->app->make(Clock::class))->toBeInstanceOf(SystemClock::class);
});

it('binds the tool-version probes (PHP / Composer / SQLite CLI / Node) through the container', function (): void {
    $php = $this->app->make(PhpVersionProbe::class);
    expect($php)->toBeInstanceOf(PhpVersionProbe::class);
    expect($php->label())->toBe('PHP');

    $composer = $this->app->make(ComposerVersionProbe::class);
    expect($composer)->toBeInstanceOf(ComposerVersionProbe::class);
    expect($composer->label())->toBe('Composer');

    $sqliteCli = $this->app->make(SqliteCliVersionProbe::class);
    expect($sqliteCli)->toBeInstanceOf(SqliteCliVersionProbe::class);
    expect($sqliteCli->label())->toBe('SQLite');

    $node = $this->app->make(NodeVersionProbe::class);
    expect($node)->toBeInstanceOf(NodeVersionProbe::class);
    expect($node->label())->toBe('Node');
});

it('PhpVersionProbe reports the current interpreter version as ok when at the minimum', function (): void {
    $probe = new PhpVersionProbe;
    $result = $probe->run();

    // The project minimum is PHP 8.5 and the runner is never below it.
    expect($result->severity)->toBe('ok');
    expect($result->message)->toContain(phpversion());
});

it('PhpVersionProbe reports critical when the interpreter is below the minimum', function (): void {
    // The runner is never below the shipped minimum, so the injectable floor
    // is the only deterministic way into the critical branch.
    $probe = new PhpVersionProbe(minPhp: '99.0');
    $result = $probe->run();

    expect($result->severity)->toBe('critical');
    expect($result->message)->toContain('minimum 99.0');
    expect($result->metadata)->toBe(['version' => phpversion(), 'min' => '99.0']);
});

it('SynchronousModeProbe never throws — IO failure is captured as a critical ProbeResult', function (): void {
    /** @var Repository $config */
    $config = $this->app->make(Repository::class);
    $config->set('database.connections.sqlite.database', '/nonexistent/path/to/beatrax-missing.sqlite');

    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    $db->purge('sqlite');

    $probe = new SynchronousModeProbe($db);
    $result = $probe->run();

    expect($result->severity)->toBe('critical');
    expect($result->message)->toContain('Failed to read PRAGMA synchronous');
    expect($result->metadata)->toHaveKey('exception');
});

it('ExternalToolVersionProbe reports warning when the tool is not available', function (): void {
    $probe = new class('Ghost', ['/nonexistent/beatrax-ghost-tool', '--version'], 'install the ghost tool') extends ExternalToolVersionProbe {};

    $result = $probe->run();

    expect($result->severity)->toBe('warning');
    expect($result->message)->toContain('install the ghost tool');
    expect($result->metadata)->toHaveKey('stderr');
});

it('BackupFreshnessProbe returns critical when the backups directory cannot be read', function (): void {
    /** @var DatabaseManager $db */
    $db = $this->app->make(DatabaseManager::class);
    /** @var Clock $clock */
    $clock = $this->app->make(Clock::class);

    // isDirectory() passes the guard, then files() blows up mid-probe — run()'s
    // try/catch has to turn that into a critical result, not propagate it.
    $files = new class extends Filesystem
    {
        public function isDirectory($directory): bool
        {
            return true;
        }

        public function files($directory, $hidden = false, $depth = 0): array
        {
            throw new RuntimeException('backups directory is unreadable');
        }
    };

    $probe = new BackupFreshnessProbe($files, $clock, $db, new UserDataPathService);
    $result = $probe->run();

    expect($result->severity)->toBe('critical');
    expect($result->message)->toContain('Failed to read backups directory');
    expect($result->metadata)->toHaveKey('exception');
});
