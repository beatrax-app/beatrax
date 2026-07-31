<?php

declare(strict_types=1);

namespace Modules\Core\Public\Services\Concerns;

// The instance-side facade over UserDataPathService's static accessors:
// every method just forwards to its static twin so the class can be injected
// and called as `$paths->backups()`. Split off the resolvers to keep the
// class under its method ceiling while reading as one delegating surface.
/**
 * @link ../../../../../.docs/features/core/architecture.md
 */
trait ProvidesInstancePathAccessors
{
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
