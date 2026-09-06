<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-browser-global-in-a-wireclick-expression
 */

/** @return list<string> absolute paths to every Blade template this repository serves */
function wireClickBladeFiles(): array
{
    $files = [];

    // Both roots the reader is served from. Modules alone left resources/views
    // — the layouts and the error pages — outside a rule that says "never".
    foreach ([base_path('Modules'), base_path('resources')] as $root) {
        if (! is_dir($root)) {
            continue;
        }

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

/**
 * wire:click, wire:submit, wire:change … the whole family shares the $wire
 * scope, and an attribute may be quoted either way.
 *
 * @return list<string>
 */
function wireClickExpressions(string $source): array
{
    // A Blade comment is not served, so an expression quoted inside one is
    // prose about the rule rather than a call site of it.
    $served = PatternScan::replace('/\{\{--.*?--\}\}/s', '', $source);

    return [
        ...PatternScan::all('/wire:[a-z.]+(?:\.[a-z]+)*="([^"]*)"/', $served)[1],
        ...PatternScan::all("/wire:[a-z.]+(?:\.[a-z]+)*='([^']*)'/", $served)[1],
    ];
}

function wireClickReachesABrowserGlobal(string $expression): bool
{
    return preg_match('/\b(document|window|navigator|localStorage|sessionStorage)\s*\./', $expression) === 1;
}

it('never reaches for a browser global from a wire: expression', function (): void {
    $files = wireClickBladeFiles();

    // The denominators, both before any verdict is read. 279 templates and 892
    // expressions today; a walk that lost a root, or a Blade dialect this
    // pattern stopped matching, reports the same empty offender list a clean
    // tree reports.
    expect(count($files))->toBeGreaterThan(150, 'the Blade walk read almost nothing — the roots are wrong, not the tree.');

    $offenders = [];
    $expressions = 0;

    foreach ($files as $path) {
        foreach (wireClickExpressions((string) file_get_contents($path)) as $expression) {
            $expressions++;

            if (wireClickReachesABrowserGlobal($expression)) {
                $offenders[] = str_replace(base_path().'/', '', $path).' — '.mb_substr($expression, 0, 80);
            }
        }
    }

    expect($expressions)->toBeGreaterThan(400, 'the walk found almost no wire: expressions at all — the pattern is wrong, not the templates.');

    sort($offenders);

    expect($offenders)->toBe([], sprintf(
        "A wire: expression is evaluated against \$wire, so a browser global there is undefined — use x-on: instead:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

it('sees a browser global in either quoting, and leaves a $wire call alone', function (): void {
    $planted = <<<'BLADE'
        <button wire:click="save()">Save</button>
        <button wire:click="window.print()">Print</button>
        <button wire:click.prevent='navigator.share(title)'>Share</button>
        <button wire:click="$set('windowOpen', true)">Open</button>
        {{-- wire:click="document.querySelector('x')" is what this rule forbids --}}
        BLADE;

    $reaching = array_values(array_filter(wireClickExpressions($planted), wireClickReachesABrowserGlobal(...)));

    expect($reaching)->toBe(
        ['window.print()', 'navigator.share(title)'],
        'The reader no longer separates a browser global from a $wire call. It must see both '
        .'quotings, must not read a Blade comment as a call site, and must not read `$set(\'windowOpen\')` '
        .'as a reach for `window`.',
    );
});
