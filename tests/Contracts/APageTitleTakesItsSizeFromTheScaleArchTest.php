<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

// Five ordinary pages sized their own title with an inline font-size, and two of
// them chose --text-xl. On the phone /reports and /counterparties wore a heading
// visibly smaller than the thirty-three pages either side of them, which reads
// as a lesser page rather than a different one. The scale is the shared decision.

it('does not let a page set its own title size in a style attribute', function (): void {
    $offenders = [];

    foreach (['Modules', 'resources'] as $root) {
        $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($root)));

        foreach ($walk as $file) {
            $path = $file->getPathname();

            if (! str_ends_with($path, '.blade.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $source = (string) file_get_contents($path);

            foreach (MarkupSource::elements($source, 'h1') as $heading) {
                if (str_contains(strtolower((string) $heading->attribute('style')), 'font-size')) {
                    $offenders[] = str_replace(base_path().'/', '', $path);
                }
            }
        }
    }

    sort($offenders);

    expect($offenders)->toBe(
        [],
        'These pages size their own title instead of taking it from the type scale, so one '
        ."page's title can shrink without any of its neighbours knowing:\n  "
        .implode("\n  ", $offenders)
    );
});
