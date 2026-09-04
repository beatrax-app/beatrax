<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-browser-global-in-a-wireclick-expression
 */
it('never reaches for a browser global from a wire: expression', function (): void {
    $offenders = [];

    /** @var iterable<SplFileInfo> $files */
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($files as $file) {
        $path = $file->getPathname();

        if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
            continue;
        }

        $source = (string) file_get_contents($path);

        // wire:click, wire:submit, wire:change … the whole family shares the
        // $wire scope.
        $matches = PatternScan::all('/wire:[a-z.]+(?:\.[a-z]+)*="([^"]*)"/', $source);

        foreach ($matches[1] as $expression) {
            if (preg_match('/\b(document|window|navigator|localStorage|sessionStorage)\s*\./', $expression) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $path).' — '.mb_substr($expression, 0, 80);
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "A wire: expression is evaluated against \$wire, so a browser global there is undefined — use x-on: instead:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
