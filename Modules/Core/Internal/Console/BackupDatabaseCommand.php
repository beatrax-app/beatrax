<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Support\BackupRetentionPolicy;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Exceptions\BackupCorruptException;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Exceptions\UnsafeBackupPathException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SqliteDatabase;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

final class BackupDatabaseCommand extends Command
{
    // Not "see system_alerts": when the LIVE database is the corrupt one, the
    // alert row cannot be written into it, and sending the operator to an empty
    // table is worse than saying what happened here.
    private const BACKUP_CORRUPT_MESSAGE = 'Backup failed — the database did not pass its integrity check.';

    /** @var string */
    protected $signature = 'db:backup {--force : Bypass the smart-skip data_version check and run unconditionally}';

    /** @var string */
    protected $description = 'Produce a consistent SQLite backup via VACUUM INTO with verification and retention pruning.';

    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $db,
        private readonly Filesystem $files,
        private readonly Clock $clock,
        private readonly BackupRetentionPolicy $retention,
        private readonly UserDataPathService $paths,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $livePath = $this->livePath();
        $backupsDir = $this->backupsDir();
        $startedAt = $this->clock->now();

        try {
            return $this->produceBackup($livePath, $backupsDir, $startedAt);
        } catch (BackupCorruptException) {
            // Each corrupt phase records its system_alerts row and prints its
            // error before throwing; this catch only maps that to one exit code.
            return self::FAILURE;
        }
    }

    private function produceBackup(string $livePath, string $backupsDir, CarbonImmutable $startedAt): int
    {
        try {
            $liveDataVersion = $this->readDataVersion($livePath);
        } catch (PDOException $e) {
            // Corrupt source, caught before VACUUM INTO runs: no file exists
            // yet, so the alert carries a null suspect path.
            $destinationForAlert = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';
            $this->failCorrupt($destinationForAlert, null, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'pragma_data_version',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }

        if ($this->option('force') !== true && $this->isSkippable($backupsDir, $liveDataVersion)) {
            $this->info(sprintf('Skipped — no commits since last backup (data_version=%d).', $liveDataVersion));

            return self::SUCCESS;
        }

        $destination = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';

        $this->vacuumInto($destination);
        $this->assertOutputExists($destination);
        $this->hardenBackupFile($destination);
        $this->assertIntegrity($destination);
        $this->writeSidecarOrFail($destination, $liveDataVersion, $startedAt);

        $this->pruneRetention($backupsDir);
        $this->info(sprintf('Backup written: %s', $destination));

        return self::SUCCESS;
    }

    private function vacuumInto(string $destination): void
    {
        try {
            // VACUUM INTO's target is a parse-time constant with no bound
            // parameters, so the path is interpolated literally. Reject
            // NUL/newline first so a later path change cannot smuggle one in.
            if (preg_match('/[\x00\n\r]/', $destination) === 1) {
                throw new UnsafeBackupPathException('Backup destination path contains an unsafe byte (NUL / newline).');
            }
            $escaped = str_replace("'", "''", $destination);
            // SQLite refuses VACUUM INTO inside a transaction, and refuses an
            // existing destination. The basename is second-resolution, so two
            // runs inside the same second is the one ambiguous case — accepted.
            $this->db->connection(SqliteDatabase::connectionName($this->config))->statement(sprintf("VACUUM INTO '%s'", $escaped));
        } catch (PDOException $e) {
            // VACUUM INTO refused the source; keep any partial output as
            // .suspect so the operator can inspect it.
            $suspect = $destination.'.suspect';
            if ($this->files->exists($destination)) {
                $this->files->move($destination, $suspect);
            }
            $this->failCorrupt($destination, $suspect, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'vacuum_into',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }
    }

    private function assertOutputExists(string $destination): void
    {
        if (! $this->files->exists($destination)) {
            $this->failCorrupt($destination, null, [
                'phase' => 'vacuum_into',
                'reason' => 'no output file produced',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }
    }

    private function hardenBackupFile(string $destination): void
    {
        // SQLite created the file via open(2), bypassing PHP's umask narrowing.
        // Filesystem::chmod returns mixed (bool on write, string-octal on read),
        // hence the explicit `=== false` sentinel below.
        if ($this->files->chmod($destination, 0o600) === false) {
            $this->files->delete($destination);
            $this->failCorrupt($destination, null, [
                'phase' => 'chmod',
                'reason' => 'chmod 0600 failed on freshly-written backup file',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }
    }

    private function assertIntegrity(string $destination): void
    {
        $integrityRows = $this->readIntegrityCheck($destination);

        if ($integrityRows !== ['ok']) {
            $suspect = $destination.'.suspect';
            $this->files->move($destination, $suspect);
            $this->failCorrupt($destination, $suspect, [
                'integrity_check' => $integrityRows,
                'phase' => 'post_vacuum',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }
    }

    private function writeSidecarOrFail(string $destination, int $liveDataVersion, CarbonImmutable $startedAt): void
    {
        $completedAt = $this->clock->now();
        try {
            $this->writeSidecar($destination, $liveDataVersion, $startedAt->toIso8601String(), $completedAt->toIso8601String());
        } catch (BackupIoException $e) {
            // A backup without its .meta.json makes the next run's smart-skip
            // read "no recent backup" and silently re-write, so the I/O failure
            // is surfaced as a critical alert rather than swallowed.
            $this->failCorrupt($destination, null, [
                'phase' => 'sidecar_write',
                'reason' => $e->getMessage(),
            ], 'Backup written but sidecar write failed — see system_alerts.');
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     *
     * @throws BackupCorruptException
     */
    private function failCorrupt(string $destination, ?string $suspectPath, array $metadata, string $consoleMessage): never
    {
        try {
            // The alert lands in the database this command just found
            // unreadable, so on the corrupt-source branch the write itself
            // throws. The console line and the exit code are the report that
            // survives that; losing them too would turn a caught corruption
            // into an unhandled crash.
            $this->recordCorruptAlert($destination, $suspectPath, $metadata);
        } catch (Throwable $e) {
            $this->logger->error('db:backup could not record its corruption alert.', SafeExceptionContext::describe($e));
        }

        $this->error($consoleMessage);

        throw new BackupCorruptException;
    }

    /**
     * @throws BackupNotSupportedException when the default connection is not sqlite
     */
    private function livePath(): string
    {
        if (! SqliteDatabase::isSqliteBuild($this->config)) {
            throw new BackupNotSupportedException('db:backup is only supported on the sqlite driver.');
        }

        $path = SqliteDatabase::livePath($this->config);
        if ($path === null) {
            throw new BackupNotSupportedException(SqliteDatabase::livePathKey($this->config).' is not configured.');
        }

        return $path;
    }

    private function backupsDir(): string
    {
        $backupsPath = $this->paths->backups();

        if (! $this->files->isDirectory($backupsPath)) {
            $this->files->makeDirectory($backupsPath, 0o755, recursive: true, force: true);
        }

        return $backupsPath;
    }

    // PRAGMA data_version is connection-local, so a fresh PDO is needed to
    // dodge the Laravel pool's cached, stale value.
    private function readDataVersion(string $sqlitePath): int
    {
        $pdo = new PDO('sqlite:'.$sqlitePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $stmt = $pdo->query('PRAGMA data_version');
        if ($stmt === false) {
            return 0;
        }
        $value = $stmt->fetchColumn();

        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * @return list<string>
     */
    private function readIntegrityCheck(string $sqlitePath): array
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

    // Missing or unreadable sidecars must fall through to "not skippable":
    // a wrong skip silently writes no backup at all.
    private function isSkippable(string $backupsDir, int $liveDataVersion): bool
    {
        $newest = $this->newestSidecarPath($backupsDir);
        if ($newest === null) {
            return false;
        }

        $decoded = json_decode((string) file_get_contents($newest), true);
        $stored = is_array($decoded) ? ($decoded['data_version'] ?? null) : null;

        return is_int($stored) && $stored === $liveDataVersion;
    }

    // Sorts basenames, not full paths, so a directory-shape change cannot flip
    // the winner: the fixed `beatrax-` + zero-padded timestamp + suffix makes
    // strcmp DESC equal newest-first.
    private function newestSidecarPath(string $backupsDir): ?string
    {
        $candidates = glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite.meta.json');
        if ($candidates === false || $candidates === []) {
            return null;
        }

        usort($candidates, static fn (string $a, string $b): int => strcmp(basename($b), basename($a)));
        $newest = $candidates[0];

        return is_file($newest) ? $newest : null;
    }

    // umask + tmp + rename + chmod, mirroring OAuthSecretsRepository::writeAtomic.
    // Every I/O return is checked so a disk-full or cross-device failure raises
    // instead of leaving a half-written or world-readable sidecar.
    private function writeSidecar(string $destination, int $dataVersion, string $startedAt, string $completedAt): void
    {
        $sidecar = $destination.'.meta.json';
        $tmp = $sidecar.'.tmp';

        $payload = json_encode([
            'data_version' => $dataVersion,
            'started_at' => $startedAt,
            'completed_at' => $completedAt,
            'integrity' => 'ok',
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

        $prevUmask = umask(0o077);
        try {
            // @-suppressed so the `=== false` checks decide: unsuppressed,
            // Laravel's handler turns each E_WARNING into an ErrorException
            // before the comparison runs, which the caller's catch misses.
            if (@file_put_contents($tmp, $payload) === false) {
                throw new BackupIoException('Failed to write backup sidecar tmp file at '.$tmp);
            }
            if (@chmod($tmp, 0o600) === false) {
                throw new BackupIoException('Failed to chmod sidecar tmp file at '.$tmp.' to 0600.');
            }
            if (@rename($tmp, $sidecar) === false) {
                throw new BackupIoException('Failed to rename sidecar tmp file to '.$sidecar.'.');
            }
            // rename() preserves the tmp file's mode on every common filesystem,
            // so this re-chmod is belt-and-braces and its failure is non-fatal.
            @chmod($sidecar, 0o600);
        } finally {
            umask($prevUmask);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // .suspect / pre-restore-* / .meta.json pass through untouched; a pruned
    // daily takes its sidecar with it.
    private function pruneRetention(string $backupsDir): void
    {
        $entries = $this->files->files($backupsDir);
        $basenames = [];
        foreach ($entries as $entry) {
            $basenames[] = $entry->getBasename();
        }

        $keepers = $this->retention->keepers($basenames);
        $keeperSet = array_flip($keepers);

        foreach ($basenames as $name) {
            if (isset($keeperSet[$name])) {
                continue;
            }
            $this->files->delete($backupsDir.DIRECTORY_SEPARATOR.$name);

            $sidecar = $backupsDir.DIRECTORY_SEPARATOR.$name.'.meta.json';
            if ($this->files->exists($sidecar)) {
                $this->files->delete($sidecar);
            }
        }
    }

    // Only the post-VACUUM integrity-check branch leaves an on-disk .suspect
    // file; every other corrupt branch passes null.
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordCorruptAlert(string $destination, ?string $suspectPath, array $metadata): void
    {
        $timestamp = $this->clock->now()->format('d M Y · H:i');
        $message = $suspectPath !== null && $this->files->exists($suspectPath)
            ? sprintf('Backup written at %s failed integrity check. Inspect %s.', $timestamp, basename($suspectPath))
            : sprintf('Backup attempted at %s aborted before any file was produced — source DB failed integrity check.', $timestamp);

        SystemAlert::create([
            'user_id' => null,
            'kind' => BackupAlertKind::Corrupt->value,
            'severity' => SystemAlertSeverity::Critical->value,
            'message' => $message,
            'metadata' => array_merge([
                'suspect_path' => $suspectPath,
                'destination' => $destination,
            ], $metadata),
        ]);
    }
}
