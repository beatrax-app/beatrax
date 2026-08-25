<?php

declare(strict_types=1);

// `flex items-baseline justify-between` puts a heading and its action on one
// row and, without `flex-wrap`, shrinks whichever of them gives way. Measured
// at 343px against the built stylesheet: a 172px button left "Terugkerend"
// 154px, 15px under what it needs, and the heading broke mid-word. The same
// row shape squeezed the import preview's buttons until "Confirm" broke.
// Neither reproduces in English, which is why both survived until a Dutch
// sweep on a 375px screen.
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
