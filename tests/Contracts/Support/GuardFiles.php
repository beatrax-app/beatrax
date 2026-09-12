<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Every file a guard in this repository is written in. A guard is not only a
// file under tests/Contracts: the shared scanners sit a directory down,
// thirteen more live beside the module they read, and tests/Helpers is where
// two CSS guards get their reading. All four roots were once outside the walk,
// so a tag-shaped pattern in any of them was excused by nobody having looked.
final class GuardFiles
{
    /** @return list<string> absolute paths */
    public static function all(): array
    {
        $paths = [];

        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('tests/Contracts'), FilesystemIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($walk as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                $paths[] = $file->getPathname();
            }
        }

        foreach (['Modules/*/tests/Arch/*.php', 'Modules/*/tests/Contracts/*.php', 'tests/Helpers/*.php'] as $pattern) {
            foreach ((array) glob(base_path($pattern)) as $path) {
                $paths[] = (string) $path;
            }
        }

        sort($paths);

        return $paths;
    }
}
