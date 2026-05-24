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
 * Produces a consistent SQLite backup of the live application database
 * via `VACUUM INTO`, validates the output with `PRAGMA integrity_check`,
 * and applies the retention sweep (7 newest dailies + 4 most-recent
 * Sundays). Each successful run writes a `.meta.json` sidecar at chmod
 * 600 capturing the source PRAGMA data_version, so a follow-up call
 * without --force can smart-skip when no commits happened since the
 * last backup.
 *
 * The command is constructor-DI'd with `DatabaseManager`, `Filesystem`,
 * `Clock`, `Repository`, the retention policy, and `UserDataPathService`,
 * which resolves the backups directory. No Laravel facade is imported or
 * called.
 *
 * Critical mechanics worth calling out:
 *  - `PRAGMA data_version` is connection-local with a per-connection
 *    cache. The smart-skip path opens a FRESH PDO against the source
 *    file to read the value rather than the Laravel-managed pool, so
 *    a stale cached value cannot mask a real write.
 *  - `VACUUM INTO` MUST NOT run inside a transaction (SQLite refuses).
 *    The statement runs outside any `->transaction(...)` block.
 *  - `VACUUM INTO` writes the destination file via SQLite's `open(2)`,
 *    bypassing PHP's umask. The command immediately calls
 *    `Filesystem::chmod(0o600)` on the output to recover the secret
 *    file convention.
 *  - The post-VACUUM integrity check uses a SECOND fresh PDO against
 *    the destination file so the result is not muddied by the
 *    Laravel-pool connection cache.
 *  - When VACUUM INTO itself throws (corrupt source), the catch arm
 *    bridges to the same corrupt-path failure surface the integrity-
 *    check branch uses: write a critical `system_alerts(backup_corrupt)`
 *    row, leave any partial output under `.suspect`, return FAILURE.
 */
final class BackupDatabaseCommand extends Command
{
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
            // Corrupt source detected before VACUUM INTO even runs. No
            // backup file or .suspect file exists on disk yet (VACUUM
            // INTO never ran), so the alert intentionally carries a
            // null suspect path — recordCorruptAlert() phrases the
            // message around "aborted before any file was produced"
            // rather than pointing the operator at a file they will
            // never find.
            $basenameForAlert = 'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';
            $destinationForAlert = $backupsDir.DIRECTORY_SEPARATOR.$basenameForAlert;
            $this->recordCorruptAlert($destinationForAlert, null, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'pragma_data_version',
            ]);
            $this->error('Backup corrupt — see system_alerts.');

            return self::FAILURE;
        }

        if ($this->option('force') !== true && $this->isSkippable($backupsDir, $liveDataVersion)) {
            $this->info(sprintf('Skipped — no commits since last backup (data_version=%d).', $liveDataVersion));

            return self::SUCCESS;
        }

        $basename = 'beatrax-'.$startedAt->format('Y-m-d-His').'.sqlite';
        $destination = $backupsDir.DIRECTORY_SEPARATOR.$basename;

        try {
            // SQLite does not accept bound parameters for VACUUM INTO's
            // target string (the target is a parse-time constant), so
            // the destination is interpolated literally. Reject any
            // path that carries shell- or SQL-hostile bytes before the
            // sprintf so a future change to the backups() directory
            // composition cannot smuggle a NUL byte, newline, or
            // unescaped single quote into the statement.
            if (preg_match('/[\x00\n\r]/', $destination) === 1) {
                throw new RuntimeException('Backup destination path contains an unsafe byte (NUL / newline).');
            }
            $escaped = str_replace("'", "''", $destination);
            // VACUUM INTO must NOT run inside a transaction (SQLite refuses).
            // The destination path must not exist yet; SQLite refuses to
            // overwrite. The basename includes seconds resolution so a
            // re-invocation in the same second is the only ambiguous case
            // — acceptable for v1.
            $this->db->connection('sqlite')->statement(sprintf("VACUUM INTO '%s'", $escaped));
        } catch (PDOException $e) {
            // Corrupt-source exception bridge: VACUUM INTO refused the
            // source DB (truncated, malformed header, etc.). The output
            // file may or may not exist; if it does, rename it to
            // .suspect for the operator to inspect. Surface the failure
            // via the same system_alerts row the integrity-check branch
            // produces.
            $suspect = $destination.'.suspect';
            if ($this->files->exists($destination)) {
                $this->files->move($destination, $suspect);
            }
            $this->recordCorruptAlert($destination, $suspect, [
                'pdo_exception' => $e->getMessage(),
                'phase' => 'vacuum_into',
            ]);
            $this->error('Backup corrupt — see system_alerts.');

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
            $this->error('Backup corrupt — see system_alerts.');

            return self::FAILURE;
        }

        // Immediately chmod 0600. `VACUUM INTO` bypassed PHP's umask
        // narrowing trick (the file was created by SQLite via open(2)),
        // so the explicit chmod is the only thing keeping the file out
        // of group / world read.
        // Filesystem::chmod returns mixed (bool on write, string-octal on
        // read), so compare against the explicit failure sentinel rather
        // than negating an unknown-typed value.
        if ($this->files->chmod($destination, 0o600) === false) {
            // Chmod failure makes the file unsafe to retain (group /
            // world readability cannot be ruled out), so it is deleted
            // immediately. The alert intentionally carries a null
            // suspect path — by the time recordCorruptAlert() runs the
            // file no longer exists, and pointing the operator at a
            // path they will never find would be misleading.
            $this->files->delete($destination);
            $this->recordCorruptAlert($destination, null, [
                'phase' => 'chmod',
                'reason' => 'chmod 0600 failed on freshly-written backup file',
            ]);
            $this->error('Backup corrupt — see system_alerts.');

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
            $this->error('Backup corrupt — see system_alerts.');

            return self::FAILURE;
        }

        $completedAt = $this->clock->now();
        try {
            $this->writeSidecar($destination, $liveDataVersion, $startedAt->toIso8601String(), $completedAt->toIso8601String());
        } catch (RuntimeException $e) {
            // Sidecar I/O failure leaves the backup file on disk but
            // without a .meta.json sidecar, which would cause the next
            // db:backup to misread "no recent backup exists" via the
            // smart-skip path and silently re-write or skip. Surface
            // the failure as the same critical system_alerts row the
            // other corrupt-path branches produce so the operator is
            // notified instead of debugging a phantom backup.
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
     * Returns the absolute path to the live SQLite database. Throws if
     * the default connection is not configured as sqlite.
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

    /**
     * Returns the absolute path of the backups directory, creating it
     * with mode 0755 on first access.
     */
    private function backupsDir(): string
    {
        $backupsPath = $this->paths->backups();

        if (! $this->files->isDirectory($backupsPath)) {
            $this->files->makeDirectory($backupsPath, 0o755, recursive: true, force: true);
        }

        return $backupsPath;
    }

    /**
     * Reads `PRAGMA data_version` via a fresh PDO connection so the
     * value is not muddied by the Laravel pool's per-connection cache.
     */
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
     * Returns the result of `PRAGMA integrity_check` against a freshly
     * opened PDO against the destination file. On success the list is
     * exactly `['ok']`; on failure the list contains one or more
     * diagnostic strings.
     *
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

    /**
     * Smart-skip predicate: returns true when the most-recent backup's
     * sidecar `.meta.json` carries the same `data_version` as the live
     * DB. Missing sidecars / unreadable JSON / missing data_version
     * keys all fall through to "not skippable" — the safer default.
     *
     * `glob()` is documented to return `array|false` — `false` on
     * permission errors or an unreadable directory. The `(array) false`
     * cast that previously bridged the return into the empty-check path
     * yields `[false]`, NOT `[]`, which subtly subverted the check.
     * The explicit `=== false` guard below is the only thing keeping
     * an unreadable backups directory from being misread as "has a
     * candidate sidecar" — load-bearing because a wrong skip would
     * silently write no backup at all.
     */
    private function isSkippable(string $backupsDir, int $liveDataVersion): bool
    {
        $candidates = glob($backupsDir.DIRECTORY_SEPARATOR.'beatrax-*.sqlite.meta.json');
        if ($candidates === false || $candidates === []) {
            return false;
        }

        // Most-recent by parsed-timestamp basename. Sorting the basenames
        // (not the full paths) means a future refactor that introduces
        // a sibling filename family or changes the parent directory
        // shape cannot accidentally flip the "newest" winner — only the
        // YYYY-MM-DD-HHMMSS suffix governs the order. The basename
        // shape is fixed (`beatrax-` prefix + zero-padded fixed-width
        // timestamp + `.sqlite.meta.json` suffix), so basename strcmp
        // descending IS chronological descending.
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

    /**
     * Writes the sidecar `.meta.json` atomically with chmod 0600. The
     * umask + tmp + rename + chmod dance mirrors
     * OAuthSecretsRepository::writeAtomic so the file is born with
     * mode 0600 and the rename is atomic on the same filesystem.
     *
     * Each I/O step has its return value checked: a `file_put_contents`
     * disk-full failure, a `chmod` permission-denied, or a `rename`
     * cross-device failure now raises a `RuntimeException` instead of
     * silently leaving the operator with a half-written or
     * group/world-readable sidecar. The exception travels up to the
     * `handle()` call site, which catches it (see writeSidecar's caller)
     * and converts it to the same corrupt-path system_alerts row the
     * other catch arms produce.
     */
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
            if (file_put_contents($tmp, $payload) === false) {
                throw new RuntimeException('Failed to write backup sidecar tmp file at '.$tmp);
            }
            if (chmod($tmp, 0o600) === false) {
                throw new RuntimeException('Failed to chmod sidecar tmp file at '.$tmp.' to 0600.');
            }
            if (rename($tmp, $sidecar) === false) {
                throw new RuntimeException('Failed to rename sidecar tmp file to '.$sidecar.'.');
            }
            // Belt-and-braces chmod on the final path. rename() preserves
            // the tmp file's mode on every common filesystem, so failure
            // here is non-fatal — but the @-suppression keeps a spurious
            // warning from polluting `db:backup` stdout in the rare case
            // the underlying filesystem rejects fchmod after the rename.
            @chmod($sidecar, 0o600);
        } finally {
            umask($prevUmask);
            if (is_file($tmp)) {
                @unlink($tmp);
            }
        }
    }

    /**
     * Deletes any matching `beatrax-YYYY-MM-DD-HHMMSS.sqlite` files
     * the retention policy did NOT keep. `.suspect`, `pre-restore-*`,
     * and `.meta.json` files are never deleted — the policy passes
     * non-matching basenames through unchanged so the caller's filter
     * naturally preserves them. The sidecar of a pruned daily IS
     * deleted alongside the daily so the directory stays consistent.
     */
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
            // The retention policy never returns a non-matching basename
            // as "to-be-pruned" — non-matching names (.suspect,
            // pre-restore-*, .meta.json) are always kept. The names that
            // reach this branch are matching daily basenames the policy
            // omitted from the keep set.
            $this->files->delete($backupsDir.DIRECTORY_SEPARATOR.$name);

            // Drop the matching sidecar alongside its parent.
            $sidecar = $backupsDir.DIRECTORY_SEPARATOR.$name.'.meta.json';
            if ($this->files->exists($sidecar)) {
                $this->files->delete($sidecar);
            }
        }
    }

    /**
     * Inserts a critical `system_alerts` row capturing the corrupt-path
     * failure. `user_id` is NULL because the alert is system-wide — the
     * operational banner surfaces it to whichever user reaches the
     * dashboard next.
     *
     * `$suspectPath` is nullable: only the post-VACUUM integrity-check
     * branch produces an on-disk `.suspect` file the operator can
     * inspect. The other corrupt-path branches (PDOException during
     * PRAGMA data_version, VACUUM INTO produced no output, chmod
     * failure with immediate delete) pass `null` so the message and
     * metadata reflect that no file exists for the operator to look at.
     *
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
