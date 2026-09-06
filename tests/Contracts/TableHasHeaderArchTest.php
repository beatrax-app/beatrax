<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

// A data table without header cells announces every cell as bare text, so a
// screen reader cannot say which column a figure belongs to. Sonar's S5256
// checks this, but it reads the raw template and resolves neither <x-core::th>
// nor a `head` slot, so it is excluded and this stands in for it.

/**
 * @return list<string>
 */
function tableHeaderBladeFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['Modules', 'resources'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

// Prose mentions a <table> often enough to matter: three of the counted
// elements under Modules/ turned out to be examples inside comments.
function tableHeaderStripComments(string $source): string
{
    $withoutBlade = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

    return preg_replace('/<!--.*?-->/s', '', $withoutBlade) ?? $withoutBlade;
}

// Any of the three ways this codebase supplies header cells: a literal <th>,
// the shared component, or the data-table head slot. Named rather than written
// inline so the control below drives the same reader the walk drives.
//
// The slot is matched on the whole name and not as a substring: `$head` inside
// `$header` or `$heading` is an ordinary variable, and a bare substring let one
// of those stand in for a header source nothing renders.
function tableHeaderIsSuppliedBy(?string $body): bool
{
    return $body !== null && (
        MarkupSource::elements($body, 'th') !== []
        || MarkupSource::elements($body, 'x-core::th') !== []
        || PatternScan::matches('/\$head\b/', $body)
    );
}

it('gives every table a header source', function (): void {
    $offenders = [];
    $files = tableHeaderBladeFiles();
    $tables = 0;

    foreach ($files as $path) {
        $source = tableHeaderStripComments((string) file_get_contents($path));

        foreach (MarkupSource::elements($source, 'table') as $table) {
            $tables++;

            if (! tableHeaderIsSuppliedBy($table->inner)) {
                $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $path).':'.$table->line($source);
            }
        }
    }

    // Both denominators, before the verdict. A walk that opened no template
    // and a lexer that recognised no <table> both report the same clean tree
    // as a tree where every table is headed, and the excluded Sonar rule that
    // used to cover this is not there to notice.
    expect(count($files))->toBeGreaterThan(
        100,
        'The walk opened '.count($files).' templates, which is too few to be the Blade tree.'
    );

    expect($tables)->toBeGreaterThan(
        10,
        'The lexer found '.$tables.' <table> elements in the whole Blade tree, which is what a reader '
        .'that stopped recognising the element looks like rather than a tree that stopped using tables.'
    );

    expect($offenders)->toBe([], sprintf(
        "A <table> with no header cells announces every cell as bare text, so a screen reader\n"
        ."cannot say which column a figure belongs to. Give it <x-core::th>, which renders\n"
        ."<th scope=\"col\"> by construction, or take the x-core::data-table head slot:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});

// A guard whose only verdict is "this list is empty" passes when its reader
// stops reading. Both halves are driven here, against a string rather than
// against the tree, so a rewrite of either cannot quietly stop finding them.
it('reads a header off each of the three sources and off none of the near misses', function (string $body, bool $headed): void {
    expect(tableHeaderIsSuppliedBy($body))->toBe($headed);
})->with([
    'a literal th' => ['<tr><th scope="col">Date</th></tr>', true],
    'the shared component' => ['<tr><x-core::th>Date</x-core::th></tr>', true],
    'the data-table head slot' => ['{{ $head }}<tr><td>1</td></tr>', true],
    'body cells only' => ['<tr><td>Date</td></tr>', false],
    'a word that merely starts with th' => ['<tr><td>there</td></tr>', false],
    'a head-shaped variable that is not the slot' => ['<tr><td>{{ $header }}</td></tr>', false],
]);
