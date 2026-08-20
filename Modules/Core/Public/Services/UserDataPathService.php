<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use InvalidArgumentException;
use Modules\Core\Public\Services\Concerns\ProvidesInstancePathAccessors;

/**
 * @link ../../../../.docs/features/core/durable-user-data-paths.md
 */
final class UserDataPathService
{
    use ProvidesInstancePathAccessors;

    // `base_path()` is the one sanctioned raw helper call in the whole
    // codebase — this class is the arch-test allow-list of size one.
    private static function projectRoot(): string
    {
        return base_path();
    }

    private static function storageRoot(): string
    {
        $native = getenv('NATIVEPHP_STORAGE_PATH');

        return is_string($native) && $native !== ''
            ? rtrim($native, '/\\')
            : self::projectRoot().DIRECTORY_SEPARATOR.'storage';
    }

    // `storage/app` holds DURABLE user data — keyring, sync identity, secrets,
    // backups — so on mobile it sits beside the database in the persisted store.
    // storageRoot() deliberately does NOT branch: storage/framework and
    // storage/logs are disposable caches, and a test pins that.
    private static function appRoot(): string
    {
        $native = getenv('NATIVEPHP_STORAGE_PATH');
        if (is_string($native) && $native !== '') {
            return rtrim($native, '/\\').DIRECTORY_SEPARATOR.'app';
        }

        if (self::isMobileRuntime()) {
            return dirname(self::projectRoot())
                .DIRECTORY_SEPARATOR.'persisted_data'
                .DIRECTORY_SEPARATOR.'storage'
                .DIRECTORY_SEPARATOR.'app';
        }

        return self::projectRoot().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'app';
    }

    public static function databaseFile(): string
    {
        // base_path() is the wiped-and-reshipped BUNDLE, carrying a populated
        // dev database.sqlite; the persisted store is empty on a fresh install.
        if (self::isMobileRuntime()) {
            return dirname(self::projectRoot())
                .DIRECTORY_SEPARATOR.'persisted_data'
                .DIRECTORY_SEPARATOR.'database'
                .DIRECTORY_SEPARATOR.'database.sqlite';
        }

        $native = getenv('NATIVEPHP_STORAGE_PATH');
        $databaseDir = is_string($native) && $native !== ''
            ? rtrim($native, '/\\').DIRECTORY_SEPARATOR.'database'
            : self::projectRoot().DIRECTORY_SEPARATOR.'database';

        return $databaseDir.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    // All three sources are read because NativePHP injects this as a server/env
    // const, not through putenv() — a bare getenv() returns false on a real
    // device and silently disables every gate that depends on it.
    public static function platform(): ?string
    {
        $platform = $_SERVER['NATIVEPHP_PLATFORM']
            ?? $_ENV['NATIVEPHP_PLATFORM']
            ?? getenv('NATIVEPHP_PLATFORM');

        return is_string($platform) && $platform !== '' ? $platform : null;
    }

    // platform() reads back null at per-request config-load in NativePHP's
    // persistent runtime, so the structural fallback carries it: the sibling
    // persisted_data directory never exists on desktop or host.
    public static function isMobileRuntime(): bool
    {
        if (self::platform() !== null) {
            return true;
        }

        return is_dir(
            dirname(self::projectRoot()).DIRECTORY_SEPARATOR.'persisted_data',
        );
    }

    public static function storageBase(): string
    {
        return self::storageRoot();
    }

    /**
     * @throws InvalidArgumentException when `$relative` contains a `..`
     *                                  path-traversal segment
     */
    public static function appPath(string $relative = ''): string
    {
        $base = self::appRoot();

        if ($relative === '') {
            return $base;
        }

        $normalised = ltrim($relative, '/\\');
        $segments = preg_split('#[/\\\\]#', $normalised);
        if ($segments !== false && in_array('..', $segments, true)) {
            throw new InvalidArgumentException(
                "Path-traversal segment '..' is not allowed in an appPath() argument: {$relative}",
            );
        }

        return $base.DIRECTORY_SEPARATOR.$normalised;
    }

    public static function backupsPath(): string
    {
        return self::appPath('backups');
    }

    // `config/logging.php` and the Dev Console tailer both read this, so the
    // panel always sees the same file Monolog writes.
    public static function logsFile(): string
    {
        return self::storageRoot().DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log';
    }

    // RotatingFileHandler rewrites logsFile() to a dated filename; an explicit
    // $date lets the tailer open yesterday or follow the rollover.
    public static function dailyLogFile(?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable;
        $base = self::logsFile();
        $dir = dirname($base);
        $name = pathinfo($base, PATHINFO_FILENAME);
        $ext = pathinfo($base, PATHINFO_EXTENSION);

        return $dir.DIRECTORY_SEPARATOR.$name.'-'.$date->format('Y-m-d').($ext !== '' ? '.'.$ext : '');
    }

    public static function logsDirectory(): string
    {
        return dirname(self::logsFile());
    }

    public static function secretsPath(): string
    {
        return self::appPath('secrets');
    }

    public static function frameworkPath(string $sub = ''): string
    {
        $base = self::storageRoot().DIRECTORY_SEPARATOR.'framework';

        return $sub === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($sub, '/\\');
    }

    // Project-rooted: module code ships inside the bundle, not user data.
    public static function modulesPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'Modules';
    }

    // Project-rooted: migrations are code, not user data.
    public static function migrationsPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    }

    // Project-rooted: `public/` serves bundle-shipped assets, not user data.
    public static function publicPath(string $relative = ''): string
    {
        $base = self::projectRoot().DIRECTORY_SEPARATOR.'public';

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    // Project-rooted catch-all for code and config paths with no own accessor.
    public static function projectPath(string $relative = ''): string
    {
        $base = self::projectRoot();

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }
}
