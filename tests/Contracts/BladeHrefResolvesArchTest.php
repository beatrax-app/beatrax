<?php

declare(strict_types=1);

use Illuminate\Routing\Router;

/**
 * @link ../../.docs/architecture/module-boundaries.md
 */

// A hand-spelled path in an href is a route name nobody checked. `route()`
// throws when its target is gone; a literal just 404s in front of the reader,
// and only on the screen that carries it — which is how `/imports` sat in the
// counterparties empty state, the one screen a user with no data reaches first.

/** @return list<string> absolute paths to every in-scope Blade template */
function bladeHrefFiles(): array
{
    $roots = [base_path('Modules'), base_path('resources')];
    $files = [];
    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// Paths this application does not own. Horizon mounts its own router, and the
// three below are framework-served assets rather than screens.
const BLADE_HREF_FOREIGN_PREFIXES = ['/horizon', '/livewire', '/flux', '/storage'];

it('points every hand-written href at a path this application serves', function (): void {
    /** @var Router $router */
    $router = app(Router::class);

    $known = [];
    foreach ($router->getRoutes() as $route) {
        $known['/'.ltrim($route->uri(), '/')] = true;
    }

    $offenders = [];
    $seen = 0;

    foreach (bladeHrefFiles() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match_all('/href="(\/[a-z0-9\/_-]*)"/i', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
            continue;
        }

        /** @var array{0: string, 1: int} $match */
        foreach ($matches[1] as $index => $match) {
            $href = $match[0];
            $seen++;

            $foreign = false;
            foreach (BLADE_HREF_FOREIGN_PREFIXES as $prefix) {
                $foreign = $foreign || str_starts_with($href, $prefix);
            }

            if ($foreign || $href === '/' || isset($known[$href])) {
                continue;
            }

            $line = substr_count(substr($source, 0, $matches[0][$index][1]), "\n") + 1;
            $offenders[] = $path.':'.$line.' — href="'.$href.'"';
        }
    }

    expect($offenders)->toBe(
        [],
        'These paths are written by hand and resolve to nothing. Use route() so a renamed or deleted '
        ."route breaks the build rather than the page.\n  "
        .implode("\n  ", $offenders),
    );

    // A scan that matches nothing reads exactly like a clean tree.
    expect($seen)->toBeGreaterThan(3);
});
