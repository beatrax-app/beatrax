<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\Filesystem;
use Modules\Core\Internal\Console\Support\BackupRetentionPolicy;
use Modules\Core\Models\SystemAlert;
use Modules\Core\Public\Contracts\Clock;
use Modules\Core\Public\Services\UserDataPathService;
use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class BackupDatabaseCommand extends Command
{
    private const BACKUP_CORRUPT_MESSAGE = 'Backup corrupt — see system_alerts.';

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
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $livePath = $this->livePath();
        $backupsDir = $this->backupsDir();

        $startedAt = $this->clock->now();

        try {
            $liveDataVersion = $this->readDataVersion($livePath);
        } catch (PDOException $e) {
            // Corrupt source detected before VACUUM INTO even runs — no
            // backup or .suspect file exists yet, so the alert's suspect
            // path is null and the message says "aborted before any file
            // was produced" rather than pointing at a nonexistent file.
            $basenameForAlert = 'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';
            $destinationForAlert = $backupsDir.DIRECTORY_SEPARATOR.$basenameForAlert;
            $this->recordCorruptAlert($destinationForAlert, null, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'pragma_data_version',
            ]);
            $this->error(self::BACKUP_CORRUPT_MESSAGE);

            return self::FAILURE;
        }

        if ($this->option('force') !== true && $this->isSkippable($backupsDir, $liveDataVersion)) {
            $this->info(sprintf('Skipped — no commits since last backup (data_version=%d).', $liveDataVersion));

            return self::SUCCESS;
        }

        $basename = 'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';
        $destination = $backupsDir.DIRECTORY_SEPARATOR.$basename;

        try {
            // VACUUM INTO's target string is a parse-time constant with no
            // bound parameters, so the destination is interpolated literally.
            // Reject shell/SQL-hostile bytes first so a future directory-
            // composition change cannot smuggle a NUL, newline, or quote in.
            if (preg_match('/[\x00\n\r]/', $destination) === 1) {
                throw new RuntimeException('Backup destination path contains an unsafe byte (NUL / newline).');
            }
            $escaped = str_replace("'", "''", $destination);
            // VACUUM INTO must NOT run inside a transaction (SQLite refuses)
            // and the destination path must not already exist. The basename
            // includes seconds resolution, so a re-invocation within the same
            // second is the one ambiguous case — acceptable for v1.
            $this->db->connection('sqlite')->statement(sprintf("VACUUM INTO '%s'", $escaped));
        } catch (PDOException $e) {
            // Corrupt-source bridge: VACUUM INTO refused the source DB
            // (truncated, malformed header, etc). If an output file exists,
            // rename it to .suspect for inspection, then surface the failure
            // via the same system_alerts row the integrity-check branch uses.
            $suspect = $destination.'.suspect';
            if ($this->files->exists($destination)) {
                $this->files->move($destination, $suspect);
            }
            $this->recordCorruptAlert($destination, $suspect, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'vacuum_into',
            ]);
            $this->error(self::BACKUP_CORRUPT_MESSAGE);

            return self::FAILURE;
        }

        if (! $this->files->exists($destination)) {
            // VACUUM INTO returned without throwing but produced no
            // output. No file landed on disk, so the alert carries a
            // null suspect path — same shape as the PDOException catch
            // arm above.
            $this->recordCorruptAlert($destination, null, [
                'phase' => 'vacuum_into',
                'reason' => 'no output file produced',
            ]);
            $this->error(self::BACKUP_CORRUPT_MESSAGE);

            return self::FAILURE;
        }

        // Immediately chmod 0600 since VACUUM INTO bypassed PHP's umask
        // narrowing (SQLite created the file via open(2)). Filesystem::chmod
        // returns mixed (bool on write, string-octal on read), so the check
        // below compares against the explicit `false` failure sentinel.
        if ($this->files->chmod($destination, 0o600) === false) {
            // Chmod failure makes the file unsafe to retain (group/world
            // readability cannot be ruled out), so it is deleted immediately.
            // The alert's suspect path is null since the file no longer
            // exists by the time recordCorruptAlert() runs.
            $this->files->delete($destination);
            $this->recordCorruptAlert($destination, null, [
                'phase' => 'chmod',
                'reason' => 'chmod 0600 failed on freshly-written backup file',
            ]);
            $this->error(self::BACKUP_CORRUPT_MESSAGE);

            return self::FAILURE;
        }

        $integrityRows = $this->readIntegrityCheck($destination);

        if ($integrityRows !== ['ok']) {
            $suspect = $destination.'.suspect';
            $this->files->move($destination, $suspect);
            $this->recordCorruptAlert($destination, $suspect, [
                'integrity_check' => $integrityRows,
                'phase' => 'post_vacuum',
            ]);
            $this->error(self::BACKUP_CORRUPT_MESSAGE);

            return self::FAILURE;
        }

        $completedAt = $this->clock->now();
        try {
            $this->writeSidecar($destination, $liveDataVersion, $startedAt->toIso8601String(), $completedAt->toIso8601String());
        } catch (RuntimeException $e) {
            // Sidecar I/O failure leaves the backup on disk without a
            // .meta.json, which would make the next db:backup misread "no
            // recent backup exists" via smart-skip and silently re-write.
            // Surface it via the same critical system_alerts row instead.
            $this->recordCorruptAlert($destination, null, [
                'phase' => 'sidecar_write',
                'reason' => $e->getMessage(),
            ]);
            $this->error('Backup written but sidecar write failed — see system_alerts.');

            return self::FAILURE;
        }
        $this->pruneRetention($backupsDir);

        $this->info(sprintf('Backup written: %s', $destination));

        return self::SUCCESS;
    }

    /**
     * @throws RuntimeException when the default connection is not sqlite
     */
    private function livePath(): string
    {
        $driver = $this->config->get('database.connections.sqlite.driver');
        if ($driver !== 'sqlite') {
            throw new RuntimeException('db:backup is only supported on the sqlite driver.');
        }

        $path = $this->config->get('database.connections.sqlite.database');
        if (! is_string($path) || $path === '') {
            throw new RuntimeException('database.connections.sqlite.database is not configured.');
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

    // Uses a fresh PDO connection so the value is not muddied by the
    // Laravel pool's per-connection cache.
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

    // Uses a freshly-opened PDO against the destination file. On success
    // the list is exactly ['ok']; on failure it holds diagnostic strings.
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

    // Smart-skip predicate: true when the most-recent sidecar carries the
    // same data_version as the live DB. Missing/unreadable sidecars fall
    // through to "not skippable" — the explicit `=== false` guard on glob()
    // is load-bearing: a wrong skip would silently write no backup at all.
    private function isSkippable(string $backupsDir, int $liveDataVersion): bool
    {
        $candidates = glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite.meta.json');
        if ($candidates === false || $candidates === []) {
            return false;
        }

        // Most-recent by parsed-timestamp basename: sorting basenames (not
        // full paths) means directory-shape changes cannot flip the winner.
        // The fixed `beatrax-` + zero-padded timestamp + `.sqlite.meta.json`
        // shape makes basename strcmp descending == chronological descending.
        usort($candidates, static fn (string $a, string $b): int => strcmp(basename($b), basename($a)));
        $newest = $candidates[0];
        if (! is_file($newest)) {
            return false;
        }

        $raw = (string) file_get_contents($newest);
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return false;
        }

        $stored = $decoded['data_version'] ?? null;

        return is_int($stored) && $stored === $liveDataVersion;
    }

    // Atomic write: umask + tmp + rename + chmod mirrors
    // OAuthSecretsRepository::writeAtomic. Every I/O step's return value is
    // checked, so a disk-full/permission/cross-device failure raises instead
    // of silently leaving a half-written or group/world-readable sidecar.
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
            // Suppressed so the `=== false` checks decide. Unsuppressed,
            // each raises E_WARNING, which Laravel's handler turns into an
            // ErrorException before the comparison runs — and that is not a
            // RuntimeException, so the caller's catch missed it too.
            if (@file_put_contents($tmp, $payload) === false) {
                throw new RuntimeException('Failed to write backup sidecar tmp file at '.$tmp);
            }
            if (@chmod($tmp, 0o600) === false) {
                throw new RuntimeException('Failed to chmod sidecar tmp file at '.$tmp.' to 0600.');
            }
            if (@rename($tmp, $sidecar) === false) {
                throw new RuntimeException('Failed to rename sidecar tmp file to '.$sidecar.'.');
            }
            // Belt-and-braces chmod: rename() preserves the tmp file's mode
            // on every common filesystem, so failure here is non-fatal — the
            // @-suppression only guards against a filesystem that rejects
            // fchmod after the rename.
            @chmod($sidecar, 0o600);
        } finally {
            umask($prevUmask);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    // Deletes matching daily .sqlite files the retention policy did NOT
    // keep; .suspect/pre-restore-*/.meta.json pass through untouched. The
    // sidecar of a pruned daily is deleted alongside it for consistency.
    private function pruneRetention(string $backupsDir): void
    {
        $entries = $this->files->files($backupsDir);
        $basenames = [];
        foreach ($entries as $entry) {
            $basenames[] = $entry->getBasename();
        }

        $keepers = $this->retention->keepers($basenames, $this->clock->now());
        $keeperSet = array_flip($keepers);

        foreach ($basenames as $name) {
            if (isset($keeperSet[$name])) {
                continue;
            }
            // The retention policy never marks a non-matching basename
            // (.suspect, pre-restore-*, .meta.json) as "to-be-pruned" — only
            // matching daily basenames the policy omitted from the keep set
            // reach this branch.
            $this->files->delete($backupsDir.DIRECTORY_SEPARATOR.$name);

            $sidecar = $backupsDir.DIRECTORY_SEPARATOR.$name.'.meta.json';
            if ($this->files->exists($sidecar)) {
                $this->files->delete($sidecar);
            }
        }
    }

    // Inserts a critical, system-wide (user_id NULL) system_alerts row.
    // $suspectPath is nullable: only the post-VACUUM integrity-check branch
    // produces an on-disk .suspect file; other corrupt-path branches pass
    // null since no file exists for the operator to look at.
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
            'kind' => 'backup_corrupt',
            'severity' => 'critical',
            'message' => $message,
            'metadata' => array_merge([
                'suspect_path' => $suspectPath,
                'destination' => $destination,
            ], $metadata),
        ]);
    }
}
