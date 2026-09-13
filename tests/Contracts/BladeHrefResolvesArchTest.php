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
//
// Translated sentences carry markup too, and they were outside this walk. One
// `/categorization` link sat in all 26 locale files of a counterparty profile,
// naming a route this application has never registered; the screens are served
// by `/uncategorized` and `/rules`. A locale file is a template with a reader.

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

// The other half of the tree that writes an href. Kept apart from the Blade
// walk rather than merged into it, because a census that cannot tell the two
// populations apart cannot notice one of them disappearing.
/** @return list<string> absolute paths to every translation file */
function langHrefFiles(): array
{
    $roots = [base_path('Modules'), base_path('lang')];
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
            if (! $file->isFile() || ! str_ends_with($path, '.php') || str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            if (preg_match('#/(?:Resources/lang|lang)/#', $path) !== 1) {
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
    $populations = ['a Blade view' => bladeHrefFiles(), 'a translation file' => langHrefFiles()];
    $seen = [];

    foreach ($populations as $kind => $files) {
        expect(count($files))->toBeGreaterThan(
            100,
            'The walk opened almost no file of the kind "'.$kind.'", so the empty offender list below '
            .'is half a tree nobody read.',
        );
    }

    expect(count($known))->toBeGreaterThan(
        20,
        'The router handed back almost no route, so every href below would resolve to nothing and report as broken.',
    );

    foreach ($populations as $kind => $files) {
        $seen[$kind] = 0;

        foreach ($files as $path) {
            $source = (string) file_get_contents($path);

            $matches = PatternScan::allWithOffsets('/href="(\/[a-z0-9\/_-]*)"/i', $source);

            /** @var array{0: string, 1: int} $match */
            foreach ($matches[1] as $index => $match) {
                $href = $match[0];
                $seen[$kind]++;

                // `/` is the root the router always answers and every layout links.
                if ($href === '/' || isset($known[$href])) {
                    continue;
                }

                $line = substr_count(substr($source, 0, $matches[0][$index][1]), "\n") + 1;
                $offenders[] = $path.':'.$line.' — href="'.$href.'"';
            }
        }
    }

    // Read BEFORE the verdict, and per population: a scan that matched nothing
    // reads exactly like a clean tree, and one half going quiet while the other
    // still answers is the shape that let 26 locale files go unread.
    foreach ($seen as $kind => $count) {
        expect($count)->toBeGreaterThan(
            3,
            'Almost no absolute href was matched in '.$kind.', so the empty offender list below is '
            .'markup nobody parsed.',
        );
    }

    expect($offenders)->toBe(
        [],
        'These paths are written by hand and resolve to nothing. Use route() so a renamed or deleted '
        ."route breaks the build rather than the page.\n  "
        .implode("\n  ", $offenders),
    );
});
