<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use InvalidArgumentException;
use Modules\Core\Public\Services\Concerns\ProvidesInstancePathAccessors;

/**
 * @link ../../../../.docs/features/core/architecture.md
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

    // `storage/app` is DURABLE user data — GDK keyring, sync identity,
    // secrets, backups — so on mobile it belongs beside the database in the
    // persisted store, not under base_path().

    // base_path() on mobile is the BUNDLE, wiped on every install. The db
    // already branched; storage/app did not, so an update destroyed the
    // keyring while the rows it decrypts survived. NativePHP leaves
    // NATIVEPHP_STORAGE_PATH unset on device, so the env branch misses it.

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
        // NativePHP mobile: base_path() is the wiped-and-reshipped app BUNDLE
        // with a populated dev database.sqlite, so using it would leak dev
        // data and defeat the fresh-install onboarding gate. Target the
        // sibling NativePHP PERSISTED store instead — empty on a fresh install.
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

    // Read-only signal (no path derives from it) so callers can detect the
    // mobile runtime. All three sources are read because NativePHP injects
    // this as a server/env const, not through putenv() — a bare getenv()
    // returns false on a real device and silently disables every gate.
    public static function platform(): ?string
    {
        $platform = $_SERVER['NATIVEPHP_PLATFORM']
            ?? $_ENV['NATIVEPHP_PLATFORM']
            ?? getenv('NATIVEPHP_PLATFORM');

        return is_string($platform) && $platform !== '' ? $platform : null;
    }

    // platform() alone is unreliable at per-request config-load in
    // NativePHP's persistent runtime (see architecture.md), so this falls
    // back to a structural check: the sibling persisted_data directory
    // NativePHP mobile provisions never exists on desktop/host.

    // Public because it is the one on-device signal the Mobile module's
    // native-capability gates and the mobile root's boot hook share.
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

    // `config/logging.php` reads this so Monolog's daily handler writes
    // under the user-data storage root (respecting NATIVEPHP_STORAGE_PATH),
    // never the project-shipped storage tree. The Dev Console log tailer
    // reads the same accessor so the panel sees the same file Monolog writes.
    public static function logsFile(): string
    {
        return self::storageRoot().DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log';
    }

    // Laravel's RotatingFileHandler rewrites logsFile()'s filename to
    // `laravel-YYYY-MM-DD.log`. `$date` defaults to today, so the tailer's
    // "open yesterday" / "follow the rollover into tomorrow" paths can pass
    // an explicit date through the same accessor Monolog itself resolves.
    public static function dailyLogFile(?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable;
        $base = self::logsFile();
        $dir = dirname($base);
        $name = pathinfo($base, PATHINFO_FILENAME);
        $ext = pathinfo($base, PATHINFO_EXTENSION);

        return $dir.DIRECTORY_SEPARATOR.$name.'-'.$date->format('Y-m-d').($ext !== '' ? '.'.$ext : '');
    }

    // The Dev Console log tailer uses this to enumerate observed channel
    // names and to compute sibling files for the rotation-detection re-open.
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

    // Always project-rooted — module code ships inside the application
    // bundle, never under the user-data storage root.
    public static function modulesPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'Modules';
    }

    // Always project-rooted — migrations are code, not user data, unlike
    // most of this class's other accessors.
    public static function migrationsPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    }

    // Always project-rooted — `public/` serves assets shipped with the
    // bundle, not user data.
    public static function publicPath(string $relative = ''): string
    {
        $base = self::projectRoot().DIRECTORY_SEPARATOR.'public';

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    // For project-rooted code and configuration paths (vendor scan globs,
    // the module-statuses file) with no dedicated accessor — these ship
    // inside the bundle, never under the user-data storage root.
    public static function projectPath(string $relative = ''): string
    {
        $base = self::projectRoot();

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }
}
