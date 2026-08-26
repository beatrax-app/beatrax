<?php

declare(strict_types=1);

// `flex items-baseline justify-between` puts a heading and its action on one
// row and, without `flex-wrap`, shrinks whichever of them gives way. Measured
// at 343px against the built stylesheet: a 172px button left "Terugkerend"
// 154px, 15px under what it needs, and the heading broke mid-word. The same
// row shape squeezed the import preview's buttons until "Confirm" broke.
// Neither reproduces in English, which is why both survived until a Dutch
// sweep on a 375px screen.
/** @return list<string> */
function headingRowBlades(): array
{
    $files = [];
    /** @var Iterator<SplFileInfo> $found */
    $found = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '/\.blade\.php$/',
    );
    foreach ($found as $file) {
        $files[] = $file->getPathname();
    }

    expect($files)->not->toBe([]);

    return $files;
}

// A column never squeezes its items sideways, and a row that already wraps has
// nothing to answer for.
function headingRowIsSqueezable(string $classes): bool
{
    if (str_contains($classes, 'flex-wrap') || str_contains($classes, 'flex-col')) {
        return false;
    }

    return str_starts_with($classes, 'flex ') || str_contains($classes, ' flex ') || str_ends_with($classes, ' flex');
}

it('lets a heading row wrap rather than squeeze what is on it', function (): void {
    /** @var list<string> $files */
    $files = [];
    /** @var Iterator<SplFileInfo> $found */
    $found = new RegexIterator(
        new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules'))),
        '/\.blade\.php$/',
    );
    foreach ($found as $file) {
        $files[] = $file->getPathname();
    }
    expect($files)->not->toBe([]);

    $offenders = [];

    foreach ($files as $path) {
        $source = (string) file_get_contents($path);
        $offset = 0;

        // Read the class list this row declares, then check the same list for
        // flex-wrap. Matching the literal rather than parsing: these are
        // Tailwind utilities in a quoted attribute, and the whole hazard is
        // that one utility is absent from a string that names the others.
        while (($at = strpos($source, 'items-baseline justify-between', $offset)) !== false) {
            $offset = $at + 1;

            $open = strrpos(substr($source, 0, $at), '"');
            if ($open === false) {
                continue;
            }

            $close = strpos($source, '"', $at);
            $classes = $close === false ? '' : substr($source, $open + 1, $close - $open - 1);

            if (! str_contains($classes, 'flex ') && ! str_starts_with($classes, 'flex')) {
                continue;
            }

            if (str_contains($classes, 'flex-wrap')) {
                continue;
            }

            $line = substr_count(substr($source, 0, $at), "\n") + 1;
            $offenders[] = str_replace(base_path().'/', '', $path).':'.$line;
        }
    }

    expect($offenders)->toBe([]);
});

// The guard above reads one literal, and the row that squeezed the forecast
// page's action spells its alignment differently: `items-start
// justify-between`. Measured on an iPhone 12 mini, "Adjust buffers →" took two
// lines in English and "Buffers aanpassen →" three in Dutch, with the arrow
// alone on the last one. What fails is the shape -- a heading and an action
// sharing a non-wrapping row -- not the word between `flex` and
// `justify-between`, so this half looks for the heading instead.
it('lets any row carrying a page heading wrap, however it aligns it', function (): void {
    $squeezed = [];

    foreach (headingRowBlades() as $path) {
        $source = (string) file_get_contents($path);
        $offset = 0;

        while (($at = strpos($source, 'justify-between', $offset)) !== false) {
            $offset = $at + 1;

            $open = strrpos(substr($source, 0, $at), '"');
            $close = strpos($source, '"', $at);
            if ($open === false || $close === false) {
                continue;
            }

            $classes = substr($source, $open + 1, $close - $open - 1);
            if (! headingRowIsSqueezable($classes)) {
                continue;
            }

            // The heading this row is drawn around, if it has one. A window
            // rather than a parsed subtree: the widest of the nine this found
            // reached its <h2> in 780 characters.
            if (preg_match('/<h[12][\s>]/', substr($source, $close, 900)) !== 1) {
                continue;
            }

            $squeezed[] = str_replace(base_path().'/', '', $path).':'.(substr_count(substr($source, 0, $at), "\n") + 1);
        }
    }

    sort($squeezed);

    expect($squeezed)->toBe(
        [],
        "These rows put a heading and its action on one line that cannot wrap:\n  ".implode("\n  ", $squeezed)
    );
});
