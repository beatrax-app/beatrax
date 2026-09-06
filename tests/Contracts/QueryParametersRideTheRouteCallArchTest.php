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

/**
 * @return list<string> "$label:$line" for every hand-glued query the source writes
 */
function routeQueryConcatLinesIn(string $source, string $label): array
{
    $offenders = [];

    foreach ([ROUTE_QUERY_CONCAT_PATTERN, ROUTE_QUERY_BLADE_PATTERN] as $pattern) {
        /** @var array{0: string, 1: int} $match */
        foreach (PatternScan::allWithOffsets($pattern, $source)[0] as $match) {
            $offenders[] = $label.':'.(substr_count(substr($source, 0, $match[1]), "\n") + 1);
        }
    }

    return $offenders;
}

it('appends a query parameter through route() rather than gluing it on afterwards', function (): void {
    $files = routeQueryConcatFiles();

    // Read before the verdict, not after it: a scan that matched nothing reads
    // exactly like a clean tree, and an assertion below the verdict is one the
    // failure report never reaches.
    expect(count($files))->toBeGreaterThan(1000, 'The walk found almost nothing, so a clean answer below is the walk being broken rather than the call sites being right.');

    $offenders = [];

    foreach ($files as $path) {
        foreach (routeQueryConcatLinesIn((string) file_get_contents($path), str_replace(base_path().'/', '', $path)) as $offender) {
            $offenders[] = $offender;
        }
    }

    expect($offenders)->toBe(
        [],
        'Pass the parameter to route() instead. Concatenation writes `?a=1?b=2` the day the route '
        ."carries a query of its own, and `b` stops being readable at all. Offenders:\n  "
        .implode("\n  ", $offenders),
    );
});

it('reads both halves of the glue and leaves the parameter passed to route() alone', function (): void {
    $concatenated = "<?php\n\$url = route('transactions.index').'?tag='.\$tag;\n";
    $inBlade = "<a href=\"{{ route('transactions.index') }}?tag={{ \$tag }}\">Tagged</a>";

    // The near misses: the shape this rule asks for, and a `?` far enough away
    // that it belongs to something else on the line.
    $passed = "<?php\n\$url = route('transactions.index', ['tag' => \$tag]);\n";
    $unrelated = "<a href=\"{{ route('transactions.index') }}\">{{ \$count > 0 ? 'some' : 'none' }}</a>";

    expect(routeQueryConcatLinesIn($concatenated, 'v'))->toBe(['v:2'])
        ->and(routeQueryConcatLinesIn($inBlade, 'v'))->toBe(['v:1'])
        ->and(routeQueryConcatLinesIn($passed, 'v'))->toBe([])
        ->and(routeQueryConcatLinesIn($unrelated, 'v'))->toBe([]);
});
