<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Backup\BackupCouldNotBeBroughtUpToDateException;
use Modules\Core\Internal\Backup\BackupFromANewerBuildException;
use Modules\Core\Internal\Backup\BackupKeyMaterial;
use Modules\Core\Internal\Backup\BackupSchemaGeneration;
use Modules\Core\Internal\Backup\LiveDatabaseTransplant;
use Modules\Core\Internal\Backup\RestoreStagingArea;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Internal\Enums\BackupFailureCause;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\RestoreRefusal;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Events\DatabaseRestored;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Exceptions\RestoreFailedException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\OwnerOnlyPath;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SqliteDatabase;
use Modules\Core\Public\Support\StoredCopy;
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
        private readonly LiveDatabaseTransplant $transplant,
        private readonly BackupKeyMaterial $keyMaterial,
        private readonly RestoreStagingArea $staging,
        private readonly OwnerOnlyPath $ownerOnly,
        private readonly BackupSchemaGeneration $schema,
        private readonly Dispatcher $events,
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

        // A backup from an older build is migrated forward before it is
        // swapped in, so a restore changes the shape of the database as well
        // as its rows. An operator agreeing to this is agreeing to that.
        $accepted = $this->confirm(sprintf(
            'Restore %s over current DB? A backup from an older build is brought to this build\'s schema first. A pre-restore snapshot will be saved. [y/N]',
            $sourcePath
        ), false);
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

        // Everything past here reads a COPY, never the file the operator
        // named: lifting the keyring out of a backup and bringing an older
        // schema forward both rewrite it. Staged unconditionally, so the
        // guarantee does not rest on a predicate agreeing with its guard.
        $staged = $this->stagedCopyOf($sourcePath);

        try {
            $this->swapIn($sourcePath, $staged, $livePath);
        } finally {
            $this->staging->discard($staged);
        }
    }

    /**
     * @throws RestoreFailedException when the copy cannot be staged
     */
    private function stagedCopyOf(string $sourcePath): string
    {
        try {
            $staged = $this->staging->path('source');

            // Owner-only first, so the copy is written into a file already at
            // 0600 rather than one born at the process umask.
            if (! $this->ownerOnly->file($staged)) {
                throw new BackupIoException('The staged backup could not be made owner-only: '.$staged);
            }
        } catch (Throwable $e) {
            $this->refuse($e);
        }

        try {
            // The main file alone: the integrity check above opened the
            // source and let go of it, and SQLite folds a WAL back in when the
            // last connection to it closes.
            if ($this->files->copy($sourcePath, $staged) === false) {
                throw new BackupIoException('The backup could not be staged for the swap: '.$staged);
            }
        } catch (Throwable $e) {
            $this->staging->discard($staged);
            $this->refuse($e);
        }

        return $staged;
    }

    /**
     * @throws RestoreFailedException on every refusal, and on a failure mid-swap
     */
    private function swapIn(string $sourcePath, string $staged, string $livePath): void
    {
        // Ahead of the snapshot and on the copy. SQLite runs no schema
        // transaction, so a run that fails part way leaves what it applied,
        // and the only undo is discarding the file it ran against. Refused
        // here, nothing has been opened and no snapshot has been written.
        $this->bringUpToDate($staged);

        $preRestorePath = $this->snapshotCurrent();

        // Before the swap, so a keyring that will not decode leaves the live
        // database untouched. Restoring the rows without it hands the reader a
        // ledger of ciphertext and calls the restore a success.
        try {
            $this->keyMaterial->unpackFrom($staged);
        } catch (Throwable $e) {
            $this->refuse($e);
        }

        // Writes the source's pages INTO the live database rather than over
        // its file, and drops every connection naming it first. `php artisan
        // down` closes nothing, so a copy landed beside a live `-wal` that the
        // next reader replayed straight back over the restored pages.
        try {
            ($this->transplant)($staged, $livePath, $preRestorePath);
        } catch (BackupIoException $e) {
            $this->recordRestoreFailureAlert($sourcePath, $livePath, $preRestorePath, [
                'phase' => 'copy',
                'reason' => $e->getMessage(),
            ]);
            $this->error('Restore failed mid-swap. Pre-restore snapshot at '.$preRestorePath.'.');

            throw new RestoreFailedException(leaveDown: true);
        }

        $this->verifySwap($sourcePath, $livePath, $preRestorePath);

        // Past the verification, so a listener reads a database this command
        // has already vouched for. The rows are in by now, so a repair that
        // throws is reported rather than turned into a failed restore.
        try {
            $this->events->dispatch(new DatabaseRestored($preRestorePath));
        } catch (Throwable $e) {
            $this->warn('The restore completed; a repair after it did not: '.SafeExceptionContext::shortName($e));
        }
    }

    /**
     * @throws RestoreFailedException when the backup is ahead of this build, or a
     *                                migration bringing it forward fails
     */
    private function bringUpToDate(string $staged): void
    {
        try {
            $ran = $this->schema->bringUpToDate($staged);
        } catch (Throwable $e) {
            $this->refuse($e);
        }

        if ($ran > 0) {
            $this->info('Brought the backup forward to this build. Migrations run: '.$ran);
        }
    }

    /**
     * @return string the absolute path of the pre-restore snapshot
     *
     * @throws RestoreFailedException when it cannot be written, or cannot carry the keyring
     */
    private function snapshotCurrent(): string
    {
        // Eight random hex characters, because VACUUM INTO refuses an existing
        // target and the stamp is second-resolution: the documented undo is to
        // restore the snapshot a failed restore just named, and two runs inside
        // one second threw a raw query exception off the safety rail itself.
        $preRestorePath = $this->backupsDirectory().DIRECTORY_SEPARATOR
            .'pre-restore-'.$this->clock->now()->format('Y-m-d-His').'-'.bin2hex(random_bytes(4)).'.sqlite';
        $escaped = str_replace("'", "''", $preRestorePath);
        // VACUUM INTO must not run inside a transaction; this call stands alone
        // on the named `sqlite` connection, which opens none.
        $this->db->connection(SqliteDatabase::connectionName($this->config))->statement(sprintf("VACUUM INTO '%s'", $escaped));
        if ($this->files->chmod($preRestorePath, 0o600) === false) {
            $this->error('Failed to chmod pre-restore snapshot to 0600; aborting.');

            throw new RestoreFailedException(leaveDown: false);
        }

        // The snapshot is an undo of the keyring as well as of the rows: the
        // lift below replaces the one on this machine, and rows put back under
        // a keyring that is no longer the active one are unreadable. Packed
        // while the machine still holds the keyring this snapshot belongs to.
        try {
            $this->keyMaterial->packInto($preRestorePath);
        } catch (Throwable $e) {
            $this->refuse($e);
        }

        $this->info('Pre-restore snapshot: '.$preRestorePath);

        return $preRestorePath;
    }

    // Framework connection, NOT a fresh PDO, so SqliteOptimizationsProvider's
    // ConnectionEstablished listener re-applies WAL + synchronous on the
    // swapped-in file.
    /**
     * @throws RestoreFailedException when the swapped-in database does not check out
     */
    private function verifySwap(string $sourcePath, string $livePath, string $preRestorePath): void
    {
        $rawIntegrity = $this->db->connection(SqliteDatabase::connectionName($this->config))->scalar('PRAGMA integrity_check');
        if ((is_string($rawIntegrity) ? $rawIntegrity : '') === 'ok') {
            return;
        }

        // Maintenance mode stays ON so the operator notices and restores
        // from the pre-restore snapshot.
        $this->recordRestoreFailureAlert($sourcePath, $livePath, $preRestorePath, [
            'phase' => 'post_swap',
            'integrity_check' => is_string($rawIntegrity) ? $rawIntegrity : '',
        ]);
        $this->error('Post-swap integrity check failed. Maintenance mode left ON; pre-restore snapshot at '.$preRestorePath.'.');

        throw new RestoreFailedException(leaveDown: true);
    }

    // Every caller can receive a PDOException as well as one of ours, and that
    // message is the statement and its bindings on a console anyone watching
    // the restore can read. Ours name a path and a phase and are the whole of
    // the advice; anything else is named by class.
    private function refuse(Throwable $e): never
    {
        $this->error('Restore refused: '.$this->because($e));

        throw new RestoreFailedException(leaveDown: false);
    }

    // A schema refusal is the one an operator acts on rather than
    // investigates, so it is spelled the way the screens spell it. The counts
    // beside it are the operator's half, and count different things: schema
    // changes this build never had, against the size of the gap it was closing.
    private function because(Throwable $e): string
    {
        if ($e instanceof BackupFromANewerBuildException) {
            return RestoreRefusal::forThrowable($e)->sentence().' ('.count($e->unmatched).' unmatched)';
        }

        if ($e instanceof BackupCouldNotBeBroughtUpToDateException) {
            return RestoreRefusal::forThrowable($e)->sentence().' ('.$e->pending.' pending)';
        }

        return $e instanceof BackupIoException
            ? $e->getMessage()
            : SafeExceptionContext::shortName($e);
    }

    /**
     * @throws BackupNotSupportedException when the connection is not sqlite, or
     *                                     carries no configured database path
     */
    private function resolveLivePath(): string
    {
        if (! SqliteDatabase::isSqliteBuild($this->config)) {
            throw new BackupNotSupportedException('db:restore is only supported on the sqlite driver.');
        }

        $path = SqliteDatabase::livePath($this->config);
        if ($path === null) {
            throw new BackupNotSupportedException(SqliteDatabase::livePathKey($this->config).' is not configured.');
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
        // The same line and the same two values the banner builds for this
        // cause, so the column and the banner cannot drift apart. The full
        // paths stay in metadata, where an operator can still read them.
        $line = CopyLine::of('core::alerts.messages.backup_restore_failed', [
            'timestamp' => CopyParam::dateAndTime($this->clock->now()),
            'snapshot' => basename($preRestorePath),
        ]);

        SystemAlert::create([
            'user_id' => null,
            'kind' => BackupAlertKind::Corrupt->value,
            'severity' => SystemAlertSeverity::Critical->value,
            'message' => $line->sentence(),
            'metadata' => array_merge(StoredCopy::inParams($line) + [
                // A restore failure shares the backup_corrupt kind with the
                // backup command, and without this the banner told the reader
                // a backup had aborted because their database was corrupt.
                'cause' => BackupFailureCause::RestoreFailed->value,
                'source_path' => $sourcePath,
                'live_path' => $livePath,
                'pre_restore_snapshot' => $preRestorePath,
            ], $extra),
        ]);
    }
}
