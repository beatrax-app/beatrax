<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Restores the live SQLite database from a backup file with three
 * load-bearing safety rails:
 *  1. Maintenance mode: refuses to run unless the app is already down
 *     OR `--force-maintenance` is passed. Maintenance mode short-
 *     circuits HTTP traffic AND causes Horizon workers to pause
 *     processing — both required so the database swap happens against
 *     a quiet system.
 *  2. Explicit `--confirm` flag: non-TTY callers (CI, scripts, agents)
 *     never proceed without it; interactive TTY sessions get a y/N
 *     prompt. The prompt's default is "no".
 *  3. Pre-swap PRAGMA integrity_check on the source file via a fresh
 *     PDO. Catching a corrupt source BEFORE the swap is the load-
 *     bearing safety property — a post-swap discovery would leave the
 *     user in a half-broken state.
 *
 * Before the file copy, the command automatically writes a `VACUUM
 * INTO`-driven snapshot of the CURRENT live DB to a
 * `pre-restore-YYYY-MM-DD-HHMMSS.sqlite` file inside the backups
 * directory at chmod 0600. This pre-restore snapshot is exempt from the
 * regular retention pruner (the BackupRetentionPolicy passes the
 * `pre-restore-*` prefix through unchanged).
 *
 * After the swap, `PRAGMA integrity_check` runs once more on the now-
 * live DB via the framework's `DatabaseManager::connection()` (NOT a
 * fresh PDO) so the `SqliteOptimizationsProvider`'s
 * `ConnectionEstablished` listener fires against the swapped-in file
 * and re-applies `journal_mode=WAL` + `synchronous=NORMAL`. A failure
 * here records a critical `system_alerts(backup_corrupt)` row, keeps
 * maintenance mode ON (even when this command brought it down),
 * leaves the pre-restore snapshot on disk, and returns FAILURE — so
 * the operator notices, manually inspects, and restores from the
 * snapshot if needed.
 *
 * No Laravel facade is imported or called; every dependency is
 * constructor-DI'd, including `UserDataPathService`, which resolves the
 * maintenance-mode down marker under the framework directory and the
 * backups directory. `BackupDatabaseCommand` injects the same service,
 * so the contract is uniform across the pair.
 */
final class RestoreDatabaseCommand extends Command
{
    /** @var string */
    protected $signature = 'db:restore {path : Path to the .sqlite backup file to restore}
        {--confirm : Skip the interactive y/N prompt}
        {--force-maintenance : Bring the app down/up automatically around the swap}';

    /** @var string */
    protected $description = 'Restore the SQLite database from a backup file with triple safety rails.';

    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $db,
        private readonly Filesystem $files,
        private readonly Kernel $artisan,
        private readonly Clock $clock,
        private readonly UserDataPathService $paths,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // Larastan narrows the `argument('path')` return to string for
        // the typed signature, so no is_string() guard is needed here.
        $sourcePath = $this->argument('path');

        if ($sourcePath === '' || ! $this->files->exists($sourcePath)) {
            $this->error('Source file not found: '.$sourcePath);

            return self::FAILURE;
        }

        $broughtDown = false;
        $leaveDown = false;
        $downMarkerPath = $this->paths->framework('down');
        $alreadyDown = $this->files->exists($downMarkerPath);

        if (! $alreadyDown && $this->option('force-maintenance') !== true) {
            $this->error('App must be in maintenance mode. Run `php artisan down` first or pass --force-maintenance.');

            return self::FAILURE;
        }

        if (! $alreadyDown && $this->option('force-maintenance') === true) {
            $this->artisan->call('down');
            $broughtDown = true;
        }

        try {
            // --confirm gate: non-TTY callers MUST pass --confirm or the
            // command refuses; interactive TTY sessions get a y/N prompt
            // (default "no"). The TTY check uses stream_isatty(STDIN), so
            // CI runners and the test harness hit the refusal deterministically.
            if ($this->option('confirm') !== true) {
                if (! (defined('STDIN') && @stream_isatty(STDIN))) {
                    $this->error('Non-interactive context — pass --confirm to proceed.');

                    return self::FAILURE;
                }

                if (! $this->confirm(sprintf('Restore %s over current DB? A pre-restore snapshot will be saved. [y/N]', $sourcePath), false)) {
                    $this->info('Restore cancelled.');

                    return self::FAILURE;
                }
            }

            // Pre-swap integrity check on the source via fresh PDO —
            // bypasses Laravel's connection pool. A non-`ok` result
            // refuses the restore BEFORE touching the live DB.
            $sourceIntegrity = $this->readIntegrityCheckFreshPdo($sourcePath);
            if ($sourceIntegrity !== ['ok']) {
                $this->error('Source file failed integrity check. Refusing to restore.');

                return self::FAILURE;
            }

            // Pre-restore snapshot of the CURRENT live DB. Resolves the
            // live path through the config (database.connections.sqlite.database).
            $livePath = $this->resolveLivePath();
            $backupsDir = $this->backupsDirectory();
            $preRestorePath = $backupsDir.DIRECTORY_SEPARATOR.'pre-restore-'.$this->clock->now()->format('Y-m-d-His').'.sqlite';
            $escaped = str_replace("'", "''", $preRestorePath);
            // VACUUM INTO must NOT run inside a transaction. The
            // sqlite-driver default does not auto-open one here; this
            // call stands alone on the named `sqlite` connection.
            $this->db->connection('sqlite')->statement(sprintf("VACUUM INTO '%s'", $escaped));
            if ($this->files->chmod($preRestorePath, 0o600) === false) {
                $this->error('Failed to chmod pre-restore snapshot to 0600; aborting.');

                return self::FAILURE;
            }
            $this->info('Pre-restore snapshot: '.$preRestorePath);

            // Release the live PDO handle so the file copy can land
            // cleanly. The next call to connection() will fire
            // ConnectionEstablished, which re-applies the WAL +
            // synchronous PRAGMAs on the swapped-in file.
            $this->db->purge('sqlite');

            // Swap: copy the source over the configured live path. On
            // failure, surface via the corrupt-path system_alerts row
            // and keep maintenance mode ON for the operator to inspect.
            if ($this->files->copy($sourcePath, $livePath) === false) {
                $leaveDown = true;
                $this->recordRestoreFailureAlert($sourcePath, $livePath, $preRestorePath, [
                    'phase' => 'copy',
                    'reason' => 'Filesystem::copy returned false during swap',
                ]);
                $this->error('Restore copy failed mid-swap. Pre-restore snapshot at '.$preRestorePath.'.');

                return self::FAILURE;
            }

            // Post-swap integrity check via the framework's connection (NOT
            // a fresh PDO) so SqliteOptimizationsProvider's
            // ConnectionEstablished listener re-applies WAL + synchronous.
            // `scalar()` returns `ok` on success, diagnostics otherwise.
            $rawIntegrity = $this->db->connection('sqlite')->scalar('PRAGMA integrity_check');
            $integrityValue = is_string($rawIntegrity) ? $rawIntegrity : '';
            if ($integrityValue !== 'ok') {
                // Critical: filesystem-level corruption during copy.
                // Keep maintenance mode ON so the operator notices and
                // restores from the pre-restore snapshot.
                $leaveDown = true;
                $this->recordRestoreFailureAlert($sourcePath, $livePath, $preRestorePath, [
                    'phase' => 'post_swap',
                    'integrity_check' => $integrityValue,
                ]);
                $this->error('Post-swap integrity check failed. Maintenance mode left ON; pre-restore snapshot at '.$preRestorePath.'.');

                return self::FAILURE;
            }

            $this->info('Restore complete from: '.$sourcePath);

            return self::SUCCESS;
        } finally {
            // Bring the app back up ONLY when (a) this command brought it
            // down AND (b) the post-swap failure branch did NOT set
            // $leaveDown. A throw before that branch still releases
            // maintenance mode — safer than locking out a healthy app.
            if ($broughtDown && ! $leaveDown) {
                $this->artisan->call('up');
            }
        }
    }

    /**
     * Returns the absolute path to the live SQLite database. Throws if
     * the configured connection is not sqlite.
     */
    private function resolveLivePath(): string
    {
        $driver = $this->config->get('database.connections.sqlite.driver');
        if ($driver !== 'sqlite') {
            throw new RuntimeException('db:restore is only supported on the sqlite driver.');
        }

        $path = $this->config->get('database.connections.sqlite.database');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('database.connections.sqlite.database is not configured.');
        }

        return $path;
    }

    /**
     * Returns the absolute path of the backups directory (resolved
     * through `UserDataPathService`), creating it with mode 0755 on
     * first access. Shape mirrors `BackupDatabaseCommand::backupsDir()`
     * so both commands depend on the same injected service.
     */
    private function backupsDirectory(): string
    {
        $backupsPath = $this->paths->backups();

        if (! $this->files->isDirectory($backupsPath)) {
            $this->files->makeDirectory($backupsPath, 0o755, recursive: true, force: true);
        }

        return $backupsPath;
    }

    /**
     * Reads `PRAGMA integrity_check` via a freshly-opened PDO. Returns
     * the column values as `list<string>`; the canonical success case
     * is `['ok']`.
     *
     * @return list<string>
     */
    private function readIntegrityCheckFreshPdo(string $sqlitePath): array
    {
        try {
            $pdo = new PDO('sqlite:'.$sqlitePath, options: [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $stmt = $pdo->query('PRAGMA integrity_check');
            if ($stmt === false) {
                return ['integrity check returned no result'];
            }
            /** @var list<string> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

            return $rows;
        } catch (Throwable $e) {
            return ['integrity check threw: '.$e->getMessage()];
        }
    }

    /**
     * Records a critical `system_alerts(backup_corrupt)` row for a
     * mid-swap or post-swap failure. The metadata captures which phase
     * tripped so the operator can correlate the alert with the on-disk
     * state.
     *
     * @param  array<string, scalar|null>  $extra
     */
    private function recordRestoreFailureAlert(string $sourcePath, string $livePath, string $preRestorePath, array $extra): void
    {
        SystemAlert::create([
            'user_id' => null,
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => sprintf(
                'Restore from %s failed at %s. Pre-restore snapshot at %s.',
                $sourcePath,
                $this->clock->now()->format('d M Y · H:i'),
                $preRestorePath,
            ),
            'metadata' => array_merge([
                'source_path' => $sourcePath,
                'live_path' => $livePath,
                'pre_restore_snapshot' => $preRestorePath,
            ], $extra),
        ]);
    }
}
