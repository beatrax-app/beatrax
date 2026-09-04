<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use InvalidArgumentException;
use Modules\Core\Public\Enums\MobilePlatform;
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

    // The application's own .env, which is where key:generate writes. It is
    // not user data, but this class is the sole sanctioned caller of
    // base_path(), so resolving it anywhere else is a boundary violation.
    public static function environmentFile(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'.env';
    }

    // One source, unlike platformSignal() below, because the two variables
    // arrive by different routes: the desktop shell passes this one in the
    // spawned process environment, and no mobile shell sets it at all — so
    // there is no server-const injection for a bare getenv() to miss here.
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
    private static function platformSignal(): ?string
    {
        $platform = $_SERVER['NATIVEPHP_PLATFORM']
            ?? $_ENV['NATIVEPHP_PLATFORM']
            ?? getenv('NATIVEPHP_PLATFORM');

        return is_string($platform) && $platform !== '' ? $platform : null;
    }

    public static function platform(): ?MobilePlatform
    {
        return MobilePlatform::tryFrom(self::platformSignal() ?? '');
    }

    // The raw signal, not platform(): a shell NativePHP names but MobilePlatform
    // does not model is still a mobile runtime, and answering false would send
    // its durable user data back into the wiped-and-reshipped bundle. The signal
    // also reads back null at per-request config-load, hence the fallback below.
    public static function isMobileRuntime(): bool
    {
        if (self::platformSignal() !== null) {
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

    public static function modulesPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'Modules';
    }

    public static function migrationsPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    }

    public static function publicPath(string $relative = ''): string
    {
        $base = self::projectRoot().DIRECTORY_SEPARATOR.'public';

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    public static function projectPath(string $relative = ''): string
    {
        $base = self::projectRoot();

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }
}
