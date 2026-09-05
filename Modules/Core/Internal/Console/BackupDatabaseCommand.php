<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Support\BackupRetentionPolicy;
use Modules\Core\Internal\Console\Support\BackupSidecar;
use Modules\Core\Internal\Enums\BackupAlertKind;
use Modules\Core\Internal\Enums\BackupFailureCause;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Enums\SystemAlertSeverity;
use Modules\Core\Public\Exceptions\BackupCorruptException;
use Modules\Core\Public\Exceptions\BackupIoException;
use Modules\Core\Public\Exceptions\BackupNotSupportedException;
use Modules\Core\Public\Exceptions\UnsafeBackupPathException;
use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\CopyLine;
use Modules\Core\Public\Support\CopyParam;
use Modules\Core\Public\Support\SafeExceptionContext;
use Modules\Core\Public\Support\SqliteDatabase;
use Modules\Core\Public\Support\StoredCopy;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Throwable;

final class BackupDatabaseCommand extends Command
{
    // Not "see system_alerts": when the LIVE database is the corrupt one, the
    // alert row cannot be written into it, and sending the operator to an empty
    // table is worse than saying what happened here.
    private const string BACKUP_CORRUPT_MESSAGE = 'Backup failed — the database did not pass its integrity check.';

    // Kept apart from the message above because they send the operator to
    // different places: one is a database to investigate, the other a disk to
    // free. Reporting the first when the second happened cost a reader a
    // corruption hunt over a full backups folder.
    private const string BACKUP_WRITE_FAILED_MESSAGE = 'Backup failed — the database is sound, its backup files could not be written.';

    // The suffix a rejected copy is kept under, so the operator has something
    // to inspect rather than a deletion.
    private const string SUSPECT_SUFFIX = '.suspect';

    /** @var string */
    protected $signature = 'db:backup {--force : Keep the copy even when it is identical to the last backup}';

    /** @var string */
    protected $description = 'Produce a verified SQLite backup via VACUUM INTO. Without --force a run whose contents match the last backup writes nothing; retention pruning — which deletes every backup the policy does not keep — runs either way.';

    public function __construct(
        private readonly Repository $config,
        private readonly DatabaseManager $db,
        private readonly Filesystem $files,
        private readonly Clock $clock,
        private readonly BackupRetentionPolicy $retention,
        private readonly BackupSidecar $sidecar,
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
            $this->assertSourceReadable($livePath);
        } catch (PDOException $e) {
            // Corrupt source, caught before VACUUM INTO runs: no file exists
            // yet, so the alert carries a null suspect path.
            $destinationForAlert = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';
            $this->failCorrupt($destinationForAlert, null, BackupFailureCause::SourceUnreadable, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'source_probe',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }

        $destination = $backupsDir.DIRECTORY_SEPARATOR.'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';

        // Staged beside the destination, because whether this copy is kept is
        // only answerable once it exists: a run that turns out to be a
        // duplicate must leave the timestamped name untouched.
        $partial = $destination.'.partial';

        $this->vacuumInto($partial, $destination);
        $this->assertOutputExists($partial, $destination);
        $this->hardenBackupFile($partial, $destination);
        $this->assertIntegrity($partial, $destination);

        // Decided on the finished copy rather than on the live file: VACUUM
        // rebuilds pages in a canonical order, so equal data gives an equal
        // digest, while the live file and its write-ahead log churn on every
        // checkpoint and every unrelated session write.
        $digest = $this->readBackupDigest($partial);

        $keepsCopy = $this->option('force') === true || ! $this->sidecar->recordsDigest($backupsDir, $digest);

        if ($keepsCopy) {
            $this->promoteOrFail($partial, $destination);
            $this->writeSidecarOrFail($destination, $digest, $startedAt);
        } else {
            $this->files->delete($partial);
        }

        // Retention is a policy over the folder, not a consequence of writing
        // to it. Pruning only on the branch that kept a copy meant the promise
        // held on the scheduled --force run and on no hand-run at all, so a
        // reader who took backups by hand kept every one of them forever.
        $this->pruneRetention($backupsDir);

        $this->info($keepsCopy
            ? sprintf('Backup written: %s', $destination)
            : 'Skipped — the database is unchanged since the last backup. Retention pruned.');

        return self::SUCCESS;
    }

    private function vacuumInto(string $destination, string $reportAs): void
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
            $suspect = $reportAs.self::SUSPECT_SUFFIX;
            if ($this->files->exists($destination)) {
                $this->files->move($destination, $suspect);
            }
            $this->failCorrupt($reportAs, $suspect, BackupFailureCause::SourceUnreadable, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'vacuum_into',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }
    }

    private function assertOutputExists(string $destination, string $reportAs): void
    {
        if (! $this->files->exists($destination)) {
            $this->failCorrupt($reportAs, null, BackupFailureCause::WriteFailed, [
                'phase' => 'vacuum_into',
                'reason' => 'no output file produced',
            ], self::BACKUP_WRITE_FAILED_MESSAGE);
        }
    }

    private function hardenBackupFile(string $destination, string $reportAs): void
    {
        // SQLite created the file via open(2), bypassing PHP's umask narrowing.
        // Filesystem::chmod returns mixed (bool on write, string-octal on read),
        // hence the explicit `=== false` sentinel below.
        if ($this->files->chmod($destination, 0o600) === false) {
            $this->files->delete($destination);
            $this->failCorrupt($reportAs, null, BackupFailureCause::WriteFailed, [
                'phase' => 'chmod',
                'reason' => 'chmod 0600 failed on freshly-written backup file',
            ], self::BACKUP_WRITE_FAILED_MESSAGE);
        }
    }

    private function assertIntegrity(string $destination, string $reportAs): void
    {
        $integrityRows = $this->readIntegrityCheck($destination);

        if ($integrityRows !== ['ok']) {
            $suspect = $reportAs.self::SUSPECT_SUFFIX;
            $this->files->move($destination, $suspect);
            $this->failCorrupt($reportAs, $suspect, BackupFailureCause::CopySuspect, [
                'integrity_check' => $integrityRows,
                'phase' => 'post_vacuum',
            ], self::BACKUP_CORRUPT_MESSAGE);
        }
    }

    private function writeSidecarOrFail(string $destination, string $digest, CarbonImmutable $startedAt): void
    {
        $completedAt = $this->clock->now();
        try {
            $this->sidecar->write($destination, $digest, $startedAt->toIso8601String(), $completedAt->toIso8601String());
        } catch (BackupIoException $e) {
            // A backup without its .meta.json makes the next run's smart-skip
            // read "no recent backup" and silently re-write, so the I/O failure
            // is surfaced as a critical alert rather than swallowed.
            $this->failCorrupt($destination, null, BackupFailureCause::WriteFailed, [
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
    private function failCorrupt(string $destination, ?string $suspectPath, BackupFailureCause $cause, array $metadata, string $consoleMessage): never
    {
        try {
            // The alert lands in the database this command just found unreadable,
            // so on the corrupt-source branch the write itself throws. The
            // console line and exit code are the report that survives that;
            // losing them too turns a caught corruption into a crash.
            $this->recordCorruptAlert($destination, $suspectPath, $cause, $metadata);
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

    /**
     * @throws PDOException when the source cannot be opened or read
     */
    private function assertSourceReadable(string $sqlitePath): void
    {
        $pdo = new PDO('sqlite:'.$sqlitePath, options: [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $pdo->query('PRAGMA schema_version');
    }

    // rename() would overwrite silently, and the name is second-resolution:
    // two runs inside one second must not let the later quietly replace the
    // backup the earlier one already verified.
    private function promoteOrFail(string $partial, string $destination): void
    {
        if ($this->files->exists($destination) || @rename($partial, $destination) === false) {
            $suspect = $destination.self::SUSPECT_SUFFIX;
            $this->files->move($partial, $suspect);
            $this->failCorrupt($destination, $suspect, BackupFailureCause::WriteFailed, [
                'phase' => 'promote',
                'reason' => 'could not rename the staged backup onto its final name',
            ], self::BACKUP_WRITE_FAILED_MESSAGE);
        }
    }

    // An unreadable digest is spelled so it can never equal a stored one:
    // failing to fingerprint the copy must mean keeping it, not discarding it.
    private function readBackupDigest(string $destination): string
    {
        $digest = @hash_file('sha256', $destination);

        return $digest === false ? 'unavailable-'.bin2hex(random_bytes(8)) : $digest;
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

            $sidecar = $backupsDir.DIRECTORY_SEPARATOR.$name.BackupSidecar::SUFFIX;
            if ($this->files->exists($sidecar)) {
                $this->files->delete($sidecar);
            }
        }
    }

    // Only the post-VACUUM integrity-check branch leaves an on-disk .suspect
    // file; every other corrupt branch passes null. The cause is recorded
    // rather than inferred from that null, which is how three failures that
    // had just cleared the database came to accuse it.
    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordCorruptAlert(string $destination, ?string $suspectPath, BackupFailureCause $cause, array $metadata): void
    {
        $timestamp = CopyParam::dateAndTime($this->clock->now());
        $keptSuspect = $suspectPath !== null && $this->files->exists($suspectPath);

        $line = $this->corruptAlertLine($cause, $timestamp, $keptSuspect ? $suspectPath : null);

        SystemAlert::create([
            'user_id' => null,
            'kind' => BackupAlertKind::Corrupt->value,
            'severity' => SystemAlertSeverity::Critical->value,
            'message' => $line->sentence(),
            'metadata' => array_merge(StoredCopy::inParams($line) + [
                'cause' => $cause->value,
                'suspect_path' => $suspectPath,
                'destination' => $destination,
            ], $metadata),
        ]);
    }

    // The three arms the banner already chooses between for this kind, named
    // here in the same order and on the same conditions, so the row and the
    // screen cannot disagree about which failure this run had.
    private function corruptAlertLine(BackupFailureCause $cause, CopyParam $timestamp, ?string $suspectPath): CopyLine
    {
        if ($suspectPath !== null) {
            return CopyLine::of('core::alerts.messages.backup_corrupt_with_path', [
                'timestamp' => $timestamp,
                'path' => basename($suspectPath),
            ]);
        }

        return CopyLine::of(
            $cause === BackupFailureCause::SourceUnreadable
                ? 'core::alerts.messages.backup_corrupt_no_path'
                : 'core::alerts.messages.backup_write_failed',
            ['timestamp' => $timestamp],
        );
    }
}
