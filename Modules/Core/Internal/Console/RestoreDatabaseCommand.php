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
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Exceptions\RestoreFailedException;
use Modules\Core\Public\Services\UserDataPathService;
use PDO;
use Throwable;

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
        $sourcePath = $this->argument('path');

        if ($sourcePath === '' || ! $this->files->exists($sourcePath)) {
            $this->error('Source file not found: '.$sourcePath);

            return self::FAILURE;
        }

        return $this->restoreWithMaintenance($sourcePath);
    }

    private function restoreWithMaintenance(string $sourcePath): int
    {
        $alreadyDown = $this->files->exists($this->paths->framework('down'));
        $forceMaintenance = $this->option('force-maintenance') === true;

        if (! $alreadyDown && ! $forceMaintenance) {
            $this->error('App must be in maintenance mode. Run `php artisan down` first or pass --force-maintenance.');

            return self::FAILURE;
        }

        $broughtDown = false;
        if (! $alreadyDown) {
            $this->artisan->call('down');
            $broughtDown = true;
        }

        $leaveDown = false;
        try {
            return $this->confirmAndSwap($sourcePath);
        } catch (RestoreFailedException $e) {
            $leaveDown = $e->leaveDown;

            return self::FAILURE;
        } finally {
            // A throw before any swap still releases maintenance mode — safer
            // than locking out a healthy app.
            if ($broughtDown && ! $leaveDown) {
                $this->artisan->call('up');
            }
        }
    }

    private function confirmAndSwap(string $sourcePath): int
    {
        if (! $this->confirmed($sourcePath)) {
            return self::FAILURE;
        }

        $this->performRestore($sourcePath);
        $this->info('Restore complete from: '.$sourcePath);

        return self::SUCCESS;
    }

    // stream_isatty(STDIN) is the gate, so CI runners and the test harness hit
    // the "pass --confirm" refusal deterministically.
    private function confirmed(string $sourcePath): bool
    {
        if ($this->option('confirm') === true) {
            return true;
        }

        if (! (defined('STDIN') && @stream_isatty(STDIN))) {
            $this->error('Non-interactive context — pass --confirm to proceed.');

            return false;
        }

        $accepted = $this->confirm(sprintf('Restore %s over current DB? A pre-restore snapshot will be saved. [y/N]', $sourcePath), false);
        if (! $accepted) {
            $this->info('Restore cancelled.');
        }

        return $accepted;
    }

    // Every failure past the point the live file is touched throws with
    // leaveDown: true, so maintenance mode outlives the command.
    private function performRestore(string $sourcePath): void
    {
        // Fresh PDO, bypassing Laravel's pool: a non-`ok` source refuses the
        // restore before the live DB is touched at all.
        if ($this->readIntegrityCheckFreshPdo($sourcePath) !== ['ok']) {
            $this->error('Source file failed integrity check. Refusing to restore.');

            throw new RestoreFailedException(leaveDown: false);
        }

        $livePath = $this->resolveLivePath();
        $preRestorePath = $this->backupsDirectory().DIRECTORY_SEPARATOR.'pre-restore-'.$this->clock->now()->format('Y-m-d-His').'.sqlite';
        $escaped = str_replace("'", "''", $preRestorePath);
        // VACUUM INTO must not run inside a transaction; this call stands alone
        // on the named `sqlite` connection, which opens none.
        $this->db->connection('sqlite')->statement(sprintf("VACUUM INTO '%s'", $escaped));
        if ($this->files->chmod($preRestorePath, 0o600) === false) {
            $this->error('Failed to chmod pre-restore snapshot to 0600; aborting.');

            throw new RestoreFailedException(leaveDown: false);
        }
        $this->info('Pre-restore snapshot: '.$preRestorePath);

        // Release the live PDO handle so the file copy can land cleanly. The
        // next call to connection() will fire ConnectionEstablished, which
        // re-applies the WAL + synchronous PRAGMAs on the swapped-in file.
        $this->db->purge('sqlite');

        if ($this->files->copy($sourcePath, $livePath) === false) {
            $this->recordRestoreFailureAlert($sourcePath, $livePath, $preRestorePath, [
                'phase' => 'copy',
                'reason' => 'Filesystem::copy returned false during swap',
            ]);
            $this->error('Restore copy failed mid-swap. Pre-restore snapshot at '.$preRestorePath.'.');

            throw new RestoreFailedException(leaveDown: true);
        }

        // Framework connection, NOT a fresh PDO, so SqliteOptimizationsProvider's
        // ConnectionEstablished listener re-applies WAL + synchronous on the
        // swapped-in file.
        $rawIntegrity = $this->db->connection('sqlite')->scalar('PRAGMA integrity_check');
        if ((is_string($rawIntegrity) ? $rawIntegrity : '') !== 'ok') {
            // Maintenance mode stays ON so the operator notices and restores
            // from the pre-restore snapshot.
            $this->recordRestoreFailureAlert($sourcePath, $livePath, $preRestorePath, [
                'phase' => 'post_swap',
                'integrity_check' => is_string($rawIntegrity) ? $rawIntegrity : '',
            ]);
            $this->error('Post-swap integrity check failed. Maintenance mode left ON; pre-restore snapshot at '.$preRestorePath.'.');

            throw new RestoreFailedException(leaveDown: true);
        }
    }

    /**
     * @throws BackupNotSupportedException when the connection is not sqlite, or
     *                                     carries no configured database path
     */
    private function resolveLivePath(): string
    {
        $driver = $this->config->get('database.connections.sqlite.driver');
        if ($driver !== 'sqlite') {
            throw new BackupNotSupportedException('db:restore is only supported on the sqlite driver.');
        }

        $path = $this->config->get('database.connections.sqlite.database');
        if (! is_string($path) || $path === '') {
            throw new BackupNotSupportedException('database.connections.sqlite.database is not configured.');
        }

        return $path;
    }

    private function backupsDirectory(): string
    {
        $backupsPath = $this->paths->backups();

        if (! $this->files->isDirectory($backupsPath)) {
            $this->files->makeDirectory($backupsPath, 0o755, recursive: true, force: true);
        }

        return $backupsPath;
    }

    /**
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
     * @param  array<string, scalar|null>  $extra
     */
    private function recordRestoreFailureAlert(string $sourcePath, string $livePath, string $preRestorePath, array $extra): void
    {
        SystemAlert::create([
            'user_id' => null,
            'kind' => 'backup_corrupt',
            'severity' => SystemAlertSeverity::Critical->value,
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
