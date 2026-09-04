<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Boot;

use Modules\Core\Public\Services\UserDataPathService;

// Records that first-launch migrations did not finish. A file rather than a
// row because the database is the thing in doubt, and a file_exists rather
// than re-asking the migrator because that means globbing every migration
// directory on a runtime where per-request work is already the slow part.
final class SchemaCompletionMarker
{
    private const string MARKER = 'schema-incomplete.marker';

    // The file carries the refusal across a relaunch; this carries it across a
    // write that failed. Without it a marker that never reached the disk read
    // back as "schema complete" — the one answer it exists to deny.
    private static bool $raisedThisProcess = false;

    public static function path(): string
    {
        return UserDataPathService::appPath(self::MARKER);
    }

    // False means the refusal is held in memory only: this launch still
    // refuses, and the next one has nothing on disk to refuse from.
    public static function raise(): bool
    {
        self::$raisedThisProcess = true;

        $path = self::path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($path, '');

        return file_exists($path);
    }

    public static function clear(): void
    {
        self::$raisedThisProcess = false;

        @unlink(self::path());
    }

    public static function isRaised(): bool
    {
        return self::$raisedThisProcess || file_exists(self::path());
    }
}
