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

    public static function path(): string
    {
        return UserDataPathService::appPath(self::MARKER);
    }

    public static function raise(): void
    {
        $path = self::path();
        $dir = dirname($path);

        if (! is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($path, '');
    }

    public static function clear(): void
    {
        @unlink(self::path());
    }

    public static function isRaised(): bool
    {
        return file_exists(self::path());
    }
}
