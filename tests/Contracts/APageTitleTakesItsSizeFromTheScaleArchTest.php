<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

// Five ordinary pages sized their own title with an inline font-size, and two of
// them chose --text-xl. On the phone /reports and /counterparties wore a heading
// visibly smaller than the thirty-three pages either side of them, which reads
// as a lesser page rather than a different one. The scale is the shared decision.

/**
 * The page titles $source draws, and how many of them size themselves from a
 * style attribute rather than from the scale.
 *
 * @return array{headings: int, sizedInline: int}
 */
function pageTitleSizingIn(string $source): array
{
    $headings = 0;
    $sizedInline = 0;

    foreach (MarkupSource::elements($source, 'h1') as $heading) {
        $headings++;

        if (str_contains(strtolower((string) $heading->attribute('style')), 'font-size')) {
            $sizedInline++;
        }
    }

    return ['headings' => $headings, 'sizedInline' => $sizedInline];
}

// A walk that opened nothing reports the same clean tree as a walk that found
// nothing. The two roots hold 279 templates drawing 33 page titles between them,
// and both floors sit far enough under those that only a broken walk or a reader
// that stopped recognising a heading trips them.
const PAGE_TITLE_TEMPLATE_FLOOR = 150;

const PAGE_TITLE_HEADING_FLOOR = 10;

it('does not let a page set its own title size in a style attribute', function (): void {
    $offenders = [];
    $templates = 0;
    $headings = 0;

    foreach (['Modules', 'resources'] as $root) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($walk as $file) {
            $path = $file->getPathname();

            // The suite's own fixtures name the shape on purpose, which is the
            // house convention for every rule about what ships.
            if (! str_ends_with($path, '.blade.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $templates++;
            $sizing = pageTitleSizingIn((string) file_get_contents($path));
            $headings += $sizing['headings'];

            if ($sizing['sizedInline'] > 0) {
                $offenders[] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    sort($offenders);

    expect($templates)->toBeGreaterThan(
        PAGE_TITLE_TEMPLATE_FLOOR,
        'The walk opened '.$templates.' templates, so its verdict covers a fraction of what a reader is shown.'
    );

    expect($headings)->toBeGreaterThan(
        PAGE_TITLE_HEADING_FLOOR,
        'The reader recognised '.$headings.' page titles in '.$templates
        .' templates, which is what a lexer that stopped reading looks like: a walk finding no title reports none sized.'
    );

    expect($offenders)->toBe(
        [],
        'These pages size their own title instead of taking it from the type scale, so one '
        ."page's title can shrink without any of its neighbours knowing:\n  "
        .implode("\n  ", $offenders)
    );
});

// A guard that cannot go red says nothing, and the verdict above is read off one
// counter. It is checked against the shapes it was written for rather than
// against the tree, so a lexer rewrite cannot quietly stop finding them.
it('finds a title that sizes itself and leaves one taking the scale alone', function (string $markup, int $headings, int $sizedInline): void {
    expect(pageTitleSizingIn($markup))->toBe(['headings' => $headings, 'sizedInline' => $sizedInline]);
})->with([
    'an inline font-size' => ['<h1 style="font-size: 1.25rem">Reports</h1>', 1, 1],
    'a shorthand font declaration is not the one banned' => ['<h1 style="font: 1.25rem/1.2 sans-serif">Reports</h1>', 1, 0],
    'another declaration in the same attribute' => ['<h1 style="color: red">Reports</h1>', 1, 0],
    'a class from the scale' => ['<h1 class="text-xl">Reports</h1>', 1, 0],
    'a heading that is not the page title' => ['<h2 style="font-size: 1rem">Totals</h2>', 0, 0],
    'no title at all' => ['<p>Nothing here</p>', 0, 0],
]);
