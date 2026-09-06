<?php

declare(strict_types=1);

use Illuminate\Routing\Router;
use Modules\Core\Public\Support\PatternScan;

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
            // Neither is first-party markup: a published vendor view is the
            // package's to route, and node_modules is not ours at all.
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// There is deliberately no foreign-prefix carve-out. The four this carried —
// /horizon, /livewire, /flux and /storage — were named by no view in the tree,
// so they excused nothing while standing ready to excuse a future path that
// merely started the same way. A view that genuinely has to link a
// framework-served path can be argued for when one exists.

// Only a double-quoted, absolute, hand-written href is read. A single-quoted
// attribute, a relative path and anything built by route() or an expression are
// outside what this can resolve, and the description says so rather than
// claiming the tree.
it('points every hand-written absolute href at a path this application serves', function (): void {
    /** @var Router $router */
    $router = app(Router::class);

    $known = [];
    foreach ($router->getRoutes() as $route) {
        $known['/'.ltrim($route->uri(), '/')] = true;
    }

    $offenders = [];
    $seen = 0;
    $views = bladeHrefFiles();

    expect(count($views))->toBeGreaterThan(
        100,
        'The walk opened almost no Blade view, so the empty offender list below is a tree nobody read.',
    );

    expect(count($known))->toBeGreaterThan(
        20,
        'The router handed back almost no route, so every href below would resolve to nothing and report as broken.',
    );

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);

        $matches = PatternScan::allWithOffsets('/href="(\/[a-z0-9\/_-]*)"/i', $source);

        /** @var array{0: string, 1: int} $match */
        foreach ($matches[1] as $index => $match) {
            $href = $match[0];
            $seen++;

            // `/` is the root the router always answers and every layout links.
            if ($href === '/' || isset($known[$href])) {
                continue;
            }

            $line = substr_count(substr($source, 0, $matches[0][$index][1]), "\n") + 1;
            $offenders[] = $path.':'.$line.' — href="'.$href.'"';
        }
    }

    // Read BEFORE the verdict: a scan that matched nothing reads exactly like a
    // clean tree. Six absolute hrefs stand on this tree, which is how few a
    // route()-first codebase leaves — the floor is under them, not near them.
    expect($seen)->toBeGreaterThan(
        3,
        'Almost no absolute href was matched, so the empty offender list below is markup nobody parsed.',
    );

    expect($offenders)->toBe(
        [],
        'These paths are written by hand and resolve to nothing. Use route() so a renamed or deleted '
        ."route breaks the build rather than the page.\n  "
        .implode("\n  ", $offenders),
    );
});
