<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services;

use InvalidArgumentException;

/**
 * Single source of truth for every filesystem path the app reads or writes.
 *
 * This class is the sole sanctioned caller of the `base_path()` helper in
 * production code — every other filesystem path resolves through one of the
 * accessors below, never through the raw `database_path()` / `storage_path()`
 * / `base_path()` helpers, so a packaged build can retarget the storage root.
 *
 * When the `NATIVEPHP_STORAGE_PATH` environment variable is set it becomes
 * the storage root (a packaged desktop build); when it is absent every
 * accessor falls back to the project-rooted paths used in local development.
 * The environment variable is read with `getenv()` — not Laravel's `env()`
 * helper — because `getenv()` is unconditional at every boot stage, which is
 * what makes the static accessors safe to call from `config/*.php` files
 * that are evaluated before the container exists.
 */
final class UserDataPathService
{
    /**
     * Project root used as the local-development fallback. `base_path()` is
     * the one sanctioned raw helper call in the whole codebase — this class
     * is the arch-test allow-list of size one.
     */
    private static function projectRoot(): string
    {
        return base_path();
    }

    /**
     * Storage root. When `NATIVEPHP_STORAGE_PATH` is set it IS the root (a
     * packaged build); absent → project-rooted `storage/` (local dev).
     */
    private static function storageRoot(): string
    {
        $native = getenv('NATIVEPHP_STORAGE_PATH');

        return is_string($native) && $native !== ''
            ? rtrim($native, '/\\')
            : self::projectRoot().DIRECTORY_SEPARATOR.'storage';
    }

    public static function databaseFile(): string
    {
        $native = getenv('NATIVEPHP_STORAGE_PATH');
        $databaseDir = is_string($native) && $native !== ''
            ? rtrim($native, '/\\').DIRECTORY_SEPARATOR.'database'
            : self::projectRoot().DIRECTORY_SEPARATOR.'database';

        return $databaseDir.DIRECTORY_SEPARATOR.'database.sqlite';
    }

    /**
     * The NativePHP mobile runtime signal (`ios`/`android`), or `null` when
     * absent (local dev, desktop, or a plain web request).
     *
     * Per 15-SPIKE-FINDINGS.md (Spike B, run on a real iPhone): NativePHP
     * mobile does NOT set `NATIVEPHP_STORAGE_PATH` — that env var stays
     * unset on-device. Instead NativePHP mobile retargets `base_path()`
     * itself into the app-sandbox container, so every accessor above
     * already resolves inside the sandbox with no dedicated mobile branch.
     * `NATIVEPHP_PLATFORM` is the reliable on-device signal; this accessor
     * exists so callers (the mobile boot-reconciliation hook) can detect
     * the mobile runtime WITHOUT introducing a `NATIVEPHP_STORAGE_PATH`
     * branch here — deliberately read-only, no path derives from it.
     */
    public static function platform(): ?string
    {
        $platform = getenv('NATIVEPHP_PLATFORM');

        return is_string($platform) && $platform !== '' ? $platform : null;
    }

    public static function storageBase(): string
    {
        return self::storageRoot();
    }

    /**
     * Join a trusted relative sub-path onto the storage `app/` directory.
     *
     * @throws InvalidArgumentException when `$relative` contains a `..`
     *                                  path-traversal segment
     */
    public static function appPath(string $relative = ''): string
    {
        $base = self::storageRoot().DIRECTORY_SEPARATOR.'app';

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

    /**
     * Daily-rolling log file path. `config/logging.php` reads this so the
     * Monolog daily handler writes under the user-data storage root —
     * never under the project-shipped storage tree — which keeps a
     * packaged build's logs co-located with the per-user database
     * file and respects the NATIVEPHP_STORAGE_PATH retarget. The
     * Dev Console's log tailer reads the same accessor so the
     * panel sees the same file Monolog writes.
     */
    public static function logsFile(): string
    {
        return self::storageRoot().DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'laravel.log';
    }

    /**
     * The actual on-disk path of today's daily-rotated log file.
     * Laravel's RotatingFileHandler takes the logsFile() path and
     * rewrites the filename to `laravel-YYYY-MM-DD.log` per the
     * file's basename. The Dev Console log tailer reads this
     * method to discover the file Monolog is currently writing
     * into; rotation detection (inode/size shrinkage) is handled
     * by the SSE controller's tail loop, but the initial open +
     * the daily roll-over to the next day's file resolve through
     * the same accessor.
     *
     * `$date` defaults to today (UTC-rounded clock); passing an explicit
     * `DateTimeInterface` lets the tailer's "open yesterday's file" and
     * "follow the rollover into tomorrow" paths share the same accessor.
     */
    public static function dailyLogFile(?\DateTimeInterface $date = null): string
    {
        $date ??= new \DateTimeImmutable;
        $base = self::logsFile();
        $dir = dirname($base);
        $name = pathinfo($base, PATHINFO_FILENAME);
        $ext = pathinfo($base, PATHINFO_EXTENSION);

        return $dir.DIRECTORY_SEPARATOR.$name.'-'.$date->format('Y-m-d').($ext !== '' ? '.'.$ext : '');
    }

    /**
     * Directory that holds the rolling laravel-YYYY-MM-DD.log
     * files. The Dev Console log tailer uses this to enumerate
     * observed channel names (when listing available log days) and
     * to compute sibling files for the rotation-detection re-open
     * path.
     */
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

    /**
     * Module code root. Always project-rooted — module code ships inside the
     * application bundle, never under the user-data storage root.
     */
    public static function modulesPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'Modules';
    }

    /**
     * Framework migration directory. Always project-rooted — migrations are
     * code, not user data.
     */
    public static function migrationsPath(): string
    {
        return self::projectRoot().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    }

    /**
     * Public asset directory. Always project-rooted — `public/` serves
     * assets shipped with the bundle, not user data.
     */
    public static function publicPath(string $relative = ''): string
    {
        $base = self::projectRoot().DIRECTORY_SEPARATOR.'public';

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    /**
     * Join a relative path onto the project root. For project-rooted code and
     * configuration paths (vendor scan globs, the module-statuses file) that
     * have no dedicated accessor — these ship inside the bundle, never under
     * the user-data storage root.
     */
    public static function projectPath(string $relative = ''): string
    {
        $base = self::projectRoot();

        return $relative === ''
            ? $base
            : $base.DIRECTORY_SEPARATOR.ltrim($relative, '/\\');
    }

    // --- Instance surface for DI consumers ---------------------------------

    public function databasePath(): string
    {
        return self::databaseFile();
    }

    public function storagePath(): string
    {
        return self::storageBase();
    }

    public function backups(): string
    {
        return self::backupsPath();
    }

    public function secrets(): string
    {
        return self::secretsPath();
    }

    public function framework(string $sub = ''): string
    {
        return self::frameworkPath($sub);
    }

    public function appRelative(string $relative): string
    {
        return self::appPath($relative);
    }
}
