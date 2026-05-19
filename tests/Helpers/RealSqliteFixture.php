<?php

declare(strict_types=1);

namespace Tests\Helpers;

use PDO;
use RuntimeException;

/**
 * Builds a real on-disk SQLite file for tests that cannot use the
 * default `:memory:` `sqlite_testing` connection. The Phase 11 backup
 * + restore commands rely on a filesystem path under the hood
 * (`VACUUM INTO '<path>'` and `PRAGMA integrity_check` against a fresh
 * `PDO` connection), and neither is meaningful against an in-memory
 * database.
 *
 * `create()` returns the absolute path to a freshly-opened `.sqlite`
 * file under `storage/framework/testing/<random>/<name>.sqlite`,
 * applies the supplied `$schemas` DDL list in order (defaulting to
 * `DEFAULT_SCHEMAS` which seeds the minimal `transactions` +
 * `system_alerts` tables Phase 11 needs), and switches the file to
 * WAL journal mode so the fixture matches the production substrate's
 * posture out of the box.
 *
 * `cleanup()` removes the `.sqlite` file and its enclosing temp
 * directory, leaving no residue between tests. Callers are expected
 * to wire `RealSqliteFixture::cleanup($path)` into a `try/finally`
 * or a Pest `afterEach()` block — the fixture intentionally does NOT
 * register a shutdown handler so multi-fixture tests can fail loudly
 * if a leak is introduced.
 *
 * The helper does not use any Laravel facade or framework binding.
 * It opens a raw `PDO('sqlite:<path>')`, calls `mkdir()` / `unlink()`
 * / `rmdir()` directly, and lives outside the Modules/ tree so the
 * arch invariants against facade usage in module code do not apply.
 *
 * Extension hook: callers needing additional tables pass a custom
 * `$schemas` list, typically as
 * `[...RealSqliteFixture::DEFAULT_SCHEMAS, 'CREATE TABLE foo (...)']`,
 * so the baseline tables stay present without forking the helper.
 */
final class RealSqliteFixture
{
    /**
     * Minimal DDL set Phase 11 needs: the transactions table (so
     * VACUUM INTO has at least one domain table to copy and the
     * sqlite_master is non-empty) plus the system_alerts table (so
     * post-restore integrity checks can assert against the operational
     * surface). Future phases needing more tables MUST pass a custom
     * `$schemas` list rather than mutating this constant.
     *
     * Schemas mirror the column shape of the real migrations closely
     * enough for backup / restore tests; they are intentionally
     * truncated (no triggers, no foreign keys) because the only
     * property under test is "the file exists and round-trips through
     * a VACUUM INTO without integrity_check tripping."
     *
     * @var list<string>
     */
    public const array DEFAULT_SCHEMAS = [
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
            created_at TEXT NOT NULL,
            acknowledged_at TEXT NULL
        )',
    ];

    /**
     * Creates a fresh on-disk SQLite file under
     * `storage/framework/testing/<random>/<name>.sqlite`, applies the
     * supplied DDL statements in order, sets WAL journal mode, and
     * returns the absolute path to the file.
     *
     * @param  list<string>  $schemas
     */
    public static function create(string $name = 'fixture', array $schemas = self::DEFAULT_SCHEMAS): string
    {
        $base = self::baseDirectory();
        if (! is_dir($base) && ! @mkdir($base, 0o755, true) && ! is_dir($base)) {
            throw new RuntimeException("Could not create base directory {$base}");
        }

        $dir = $base.DIRECTORY_SEPARATOR.bin2hex(random_bytes(8));
        if (! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create fixture directory {$dir}");
        }

        $path = $dir.DIRECTORY_SEPARATOR.$name.'.sqlite';

        $pdo = new PDO('sqlite:'.$path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // PRAGMA journal_mode = WAL must be set on a fresh, valid DB
        // file; calling it before any DDL ensures the WAL header is
        // committed before user tables exist.
        $pdo->exec('PRAGMA journal_mode = WAL');

        foreach ($schemas as $ddl) {
            $pdo->exec($ddl);
        }

        // Drop the PDO handle so any subsequent VACUUM INTO / fresh
        // PDO open against the same path does not race against an
        // open write connection.
        unset($pdo);

        return $path;
    }

    /**
     * Removes the `.sqlite` file (plus any WAL / SHM sidecars SQLite
     * may have produced) and the enclosing per-fixture temp
     * directory. Safe to call multiple times — missing files are
     * silently ignored.
     */
    public static function cleanup(string $path): void
    {
        foreach ([$path, $path.'-wal', $path.'-shm', $path.'-journal'] as $candidate) {
            if (is_file($candidate)) {
                @unlink($candidate);
            }
        }

        $dir = dirname($path);
        if (is_dir($dir) && self::isEmpty($dir)) {
            @rmdir($dir);
        }
    }

    /**
     * Base directory for all fixture files. Lives under
     * `storage/framework/testing/` so the existing framework gitignore
     * already excludes it from version control.
     */
    private static function baseDirectory(): string
    {
        return rtrim(getcwd() ?: sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'storage'
            .DIRECTORY_SEPARATOR.'framework'
            .DIRECTORY_SEPARATOR.'testing'
            .DIRECTORY_SEPARATOR.'real-sqlite';
    }

    private static function isEmpty(string $dir): bool
    {
        $iterator = @scandir($dir);
        if ($iterator === false) {
            return false;
        }

        $entries = array_diff($iterator, ['.', '..']);

        return $entries === [];
    }
}
