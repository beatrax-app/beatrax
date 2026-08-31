<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#px-8-page-padding-on-a-phone
 */

/** @return list<string> */
function pageShellFiles(): array
{
    $files = [];

    /** @var iterable<SplFileInfo> $found */
    $found = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($found as $file) {
        if (! $file->isFile() || ! str_ends_with($file->getPathname(), '.blade.php')) {
            continue;
        }

        // A routed view that extends the app layout is a page shell too, and
        // scanning only the livewire directory is how /community kept a bare
        // px-6: at the reader's largest accessibility text that gutter was
        // 80px a side, and the note nested two boxes inside it was handed a
        // content width of zero.
        // The components a page shell is built from count too: x-core::page-shell
        // itself carried a bare px-8 through nineteen callers, and neither branch
        // above reached it -- it is not under /livewire/ and it extends nothing.
        $isPageShell = str_contains($file->getPathname(), '/Resources/views/livewire/')
            || str_contains($file->getPathname(), '/Resources/views/components/')
            || str_contains((string) file_get_contents($file->getPathname()), "@extends('layouts.app'");

        if (! $isPageShell) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

it('never starts a page shell wider than px-4 on a phone', function (): void {
    $offenders = [];
    $columns = 0;

    foreach (pageShellFiles() as $path) {
        $source = (string) file_get_contents($path);

        // Anchored on `class="mx-auto`, this read seven page containers as
        // having no column at all: `max-w-5xl mx-auto px-6` puts the width
        // first, and /settings/aliases shipped the 24px phone gutter this rule
        // exists to forbid while the rule reported green.
        preg_match_all('/class="[^"]*\bmx-auto\b[^"]*"/', $source, $matches);

        $columns += count($matches[0]);

        foreach ($matches[0] as $class) {
            // A responsive bump (sm:px-8) is the intended shape; a bare
            // px-6/px-8 applies at every width, phone included.
            if (preg_match('/(?<!:)px-[68]\b/', $class) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $path).' — '.$class;
            }
        }
    }

    expect($columns)->toBeGreaterThan(30, 'The column scan found almost nothing, so this rule went blind rather than the tree being clean.');

    expect($offenders)->toBe([], sprintf(
        "These page shells are wider than px-4 at phone width:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
