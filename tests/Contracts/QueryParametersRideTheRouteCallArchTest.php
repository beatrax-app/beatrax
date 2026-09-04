<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/architecture/navigation-destinations.md
 */

// route() already writes a query for anything it was not given as a path
// segment, so a second `?` glued on by hand collapses the whole tail into one
// unreadable value. The two forms agree only while the route has no query of
// its own — a property of the route, not of the call site that assumed it.

/** @return list<string> absolute paths to every in-scope PHP source file */
function routeQueryConcatFiles(): array
{
    $roots = ['Modules', 'app', 'resources', 'routes', 'tests'];
    $files = [];
    foreach ($roots as $root) {
        $path = base_path($root);
        if (! is_dir($path)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $name = $file->getPathname();
            if (! $file->isFile() || ! str_ends_with($name, '.php')) {
                continue;
            }
            if (str_contains($name, '/vendor/') || str_contains($name, '/node_modules/')) {
                continue;
            }
            $files[] = $name;
        }
    }
    sort($files);

    return $files;
}

// One level of nesting is enough for `route($x, foo($y))`; a call list deeper
// than that has never appeared in this tree.
const ROUTE_QUERY_CONCAT_PATTERN = '/route\s*\((?:[^()]|\([^()]*\))*\)\s*\.\s*[\'"]\?/';

// Blade glues the same two halves together without a concatenation operator:
// an echo holding a route() call, closed, and a `?` in the markup right after
// it. Bounded to a single echo so a later `?` on the same line cannot match.
const ROUTE_QUERY_BLADE_PATTERN = '/\{\{(?:(?!\}\})[\s\S])*?\broute\s*\((?:(?!\}\})[\s\S])*?\}\}\?/';

it('appends a query parameter through route() rather than gluing it on afterwards', function (): void {
    $offenders = [];
    $scanned = 0;

    foreach (routeQueryConcatFiles() as $path) {
        $source = (string) file_get_contents($path);
        $scanned++;

        foreach ([ROUTE_QUERY_CONCAT_PATTERN, ROUTE_QUERY_BLADE_PATTERN] as $pattern) {
            $matches = PatternScan::allWithOffsets($pattern, $source);

            /** @var array{0: string, 1: int} $match */
            foreach ($matches[0] as $match) {
                $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
                $offenders[] = str_replace(base_path().'/', '', $path).':'.$line;
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'Pass the parameter to route() instead. Concatenation writes `?a=1?b=2` the day the route '
        ."carries a query of its own, and `b` stops being readable at all. Offenders:\n  "
        .implode("\n  ", $offenders),
    );

    // A scan that matches nothing reads exactly like a clean tree.
    expect($scanned)->toBeGreaterThan(100);
});
