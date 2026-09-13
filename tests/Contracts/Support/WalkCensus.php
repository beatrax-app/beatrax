<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// A floor catches exactly one failure: a walk that read nothing. It says
// nothing about a walk that read almost everything and lost one module, which
// is the shape a narrowing actually takes — one more fragment in a skip list,
// one more clause in a path filter. Seventeen guards of twenty planted passed
// green with a module unread, their floors never moving.
/**
 * @link ../../../.docs/conventions/arch-invariants.md#a-floor-does-not-notice-a-module-going-missing
 */
final class WalkCensus
{
    /** @var array<string, list<string>> */
    private static array $holding = [];

    /**
     * The modules the tree holds files of this kind for, read off the
     * filesystem rather than off the walk, so a narrowed walk has an
     * independent reader of the same question to disagree with.
     *
     * @param  list<string>  $paths  absolute or repository-relative
     * @return list<string> module names the tree holds source for and the walk reached none of
     */
    public static function modulesMissedBy(array $paths, string $suffix = '.php', string $under = ''): array
    {
        $reached = [];

        foreach ($paths as $path) {
            $segments = explode('/', self::relative($path));

            if (count($segments) > 2 && $segments[0] === 'Modules') {
                $reached[$segments[1]] = true;
            }
        }

        $missed = [];

        foreach (self::modulesHolding($suffix, $under) as $module) {
            if (! array_key_exists($module, $reached)) {
                $missed[] = $module;
            }
        }

        return $missed;
    }

    /**
     * @param  list<string>  $paths  absolute or repository-relative
     * @return array<string, int> top-level directory => how many of the walk's files sit under it
     */
    public static function byRoot(array $paths): array
    {
        $counts = [];

        foreach ($paths as $path) {
            $root = explode('/', self::relative($path))[0];
            $counts[$root] = ($counts[$root] ?? 0) + 1;
        }

        ksort($counts);

        return $counts;
    }

    /**
     * @return list<string> every module under Modules/ holding a shipped file of this kind
     */
    public static function modulesHolding(string $suffix, string $under = ''): array
    {
        if (isset(self::$holding[$suffix.'|'.$under])) {
            return self::$holding[$suffix.'|'.$under];
        }

        $holding = [];

        foreach ((array) glob(RepoTree::root().'/Modules/*', GLOB_ONLYDIR) as $directory) {
            if (is_string($directory) && self::holdsShippedFile($directory, $suffix, $under)) {
                $holding[] = basename($directory);
            }
        }

        sort($holding);

        return self::$holding[$suffix.'|'.$under] = $holding;
    }

    private static function holdsShippedFile(string $directory, string $suffix, string $under): bool
    {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($walk as $file) {
            $path = str_replace(DIRECTORY_SEPARATOR, '/', $file->getPathname());

            if (! $file->isFile() || ! str_ends_with($path, $suffix)) {
                continue;
            }

            if ($under !== '' && ! str_contains($path, $under)) {
                continue;
            }

            // A module's own suite is not what any of these walks is about, and
            // `.php` must not answer for a template that merely ends in it.
            if (str_contains($path, '/tests/') || str_contains($path, '/Tests/')) {
                continue;
            }

            if ($suffix === '.php' && str_ends_with($path, '.blade.php')) {
                continue;
            }

            return true;
        }

        return false;
    }

    // mobile-app/ is the second Composer root and its Modules is a symlink to
    // the one walked here, so a path read from that root arrives one segment
    // deeper and every module would read as unreached.
    private static function relative(string $path): string
    {
        $relative = str_replace(RepoTree::root().'/', '', str_replace(DIRECTORY_SEPARATOR, '/', $path));

        return str_starts_with($relative, 'mobile-app/') ? substr($relative, strlen('mobile-app/')) : $relative;
    }
}
