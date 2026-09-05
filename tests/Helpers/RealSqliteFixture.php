<?php

declare(strict_types=1);

namespace Tests\Helpers;

use PDO;
use RuntimeException;

// The backup and restore commands run `VACUUM INTO '<path>'` and open a fresh
// PDO for `PRAGMA integrity_check`; neither is meaningful against the default
// `:memory:` sqlite_testing connection. No shutdown handler is registered on
// purpose, so a fixture a test forgets to clean up fails loudly.
final class RealSqliteFixture
{
    // Truncated on purpose — no triggers, no foreign keys — because the only
    // property under test is that the file round-trips a VACUUM INTO with
    // integrity_check clean. Callers needing more tables pass their own
    // $schemas list rather than growing this one.
    /**
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
            acknowledged_at TEXT NULL,
            dedup_key TEXT NULL
        )',
        'CREATE UNIQUE INDEX system_alerts_dedup_key_unique ON system_alerts (dedup_key)',
    ];

    /**
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
        // Before any DDL, so the WAL header is committed on a still-empty file.
        $pdo->exec('PRAGMA journal_mode = WAL');

        foreach ($schemas as $ddl) {
            $pdo->exec($ddl);
        }

        // A later VACUUM INTO or fresh PDO open must not race an open write
        // connection to the same path.
        unset($pdo);

        return $path;
    }

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

    // Under storage/framework/testing/ so the framework gitignore already
    // covers it.
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
