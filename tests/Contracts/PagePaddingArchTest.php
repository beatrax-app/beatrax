<?php

declare(strict_types=1);

/*
 * Page shells agree on their phone-width padding. Most use px-4; three had
 * px-8, which at 411px spends 64px of a 411px screen on empty margin instead
 * of 32px, and reads as cramped next to every other page.
 *
 * Wider padding above the sm breakpoint is fine and encouraged — the rule is
 * only that a page may not start wider than px-4 on a phone.
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

        if (! str_contains($file->getPathname(), '/Resources/views/livewire/')) {
            continue;
        }

        $files[] = $file->getPathname();
    }

    sort($files);

    return $files;
}

it('never starts a page shell wider than px-4 on a phone', function (): void {
    $offenders = [];

    foreach (pageShellFiles() as $path) {
        $source = (string) file_get_contents($path);

        preg_match_all('/class="mx-auto[^"]*"/', $source, $matches);

        foreach ($matches[0] as $class) {
            // A responsive bump (sm:px-8) is the intended shape; a bare
            // px-6/px-8 applies at every width, phone included.
            if (preg_match('/(?<!:)px-[68]\b/', $class) === 1) {
                $offenders[] = str_replace(base_path().'/', '', $path).' — '.$class;
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "These page shells are wider than px-4 at phone width:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
