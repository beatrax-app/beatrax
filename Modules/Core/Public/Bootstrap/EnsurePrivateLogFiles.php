<?php

declare(strict_types=1);

namespace Modules\Core\Public\Bootstrap;

use Modules\Core\Public\Services\UserDataPathService;
use Modules\Core\Public\Support\OwnerOnlyPath;

/**
 * @link ../../../../.docs/architecture/owner-only-paths.md#the-log-is-user-data-too
 */
final readonly class EnsurePrivateLogFiles
{
    public function __construct(private OwnerOnlyPath $paths) {}

    // The directory is the half that has to hold: LogManager builds the
    // emergency channel's StreamHandler with no permission argument, so a
    // `permission` key there would read as decided and do nothing. 0700 also
    // covers the files an earlier install already rotated out at 0644.
    public function run(): void
    {
        $directory = UserDataPathService::logsDirectory();

        $this->paths->directory($directory);

        foreach ($this->existingLogFiles($directory) as $file) {
            $this->paths->file($file);
        }
    }

    // Only paths that already exist, because OwnerOnlyPath::file() creates a
    // missing one, and inventing tomorrow's rotated log would leave the Dev
    // Console tailer an empty day it would then offer to open.
    /**
     * @return list<string>
     */
    private function existingLogFiles(string $directory): array
    {
        $matches = glob($directory.DIRECTORY_SEPARATOR.'*.log');

        if ($matches === false) {
            return [];
        }

        return array_values(array_filter($matches, is_file(...)));
    }
}
