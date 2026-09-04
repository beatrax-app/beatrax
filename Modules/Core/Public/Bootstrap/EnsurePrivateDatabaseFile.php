<?php

declare(strict_types=1);

namespace Modules\Core\Public\Bootstrap;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;

/**
 * @link ../../../../.docs/architecture/sqlite-file-precreation.md
 */
final readonly class EnsurePrivateDatabaseFile
{
    private const int DIRECTORY_MODE = 0o775;

    public function __construct(private OwnerOnlyPath $paths) {}

    // SQLite gives -wal and -shm the database file's own mode, so this single
    // decision covers the recently written pages too. It runs on every boot
    // rather than only at creation: a database restored by a copy that did not
    // preserve modes arrives at 0644, and nothing else would ever narrow it.
    public function run(): void
    {
        $file = UserDataPathService::databaseFile();
        $directory = dirname($file);

        if (! is_dir($directory)) {
            @mkdir($directory, self::DIRECTORY_MODE, true);
        }

        $this->paths->file($file);
    }
}
