<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/*
 * Every layout that can host a file input has to carry the upload-transport
 * signal, because the client reads it to decide whether multipart can cross at
 * all — and on the mobile runtimes it cannot.
 *
 * This exists because the signal was added to the two shared layouts and the
 * wizard's own layout was missed. The wizard is where the FIRST import
 * happens, so the one screen that most needed the encoded transport was the
 * one screen that did not get it, and every check short of driving a real
 * device said the fix was in.
 */

/** @return array<string, string> layout path => contents */
function beatraxLayouts(): array
{
    $finder = (new Finder)
        ->files()
        ->name('*.blade.php')
        ->path('layouts')
        ->in([base_path('resources/views'), base_path('Modules')]);

    $layouts = [];

    foreach ($finder as $file) {
        $layouts[str_replace(base_path().'/', '', $file->getPathname())] = $file->getContents();
    }

    return $layouts;
}

it('finds the layouts at all, so a passing run means something', function (): void {
    expect(beatraxLayouts())->not->toBeEmpty();
});

it('carries the upload-transport signal in every layout that carries a CSRF token', function (): void {
    // The CSRF token marks a layout that hosts real, posting UI — which is the
    // same set that can host a file input.
    $missing = [];

    foreach (beatraxLayouts() as $path => $contents) {
        if (! str_contains($contents, 'csrf-token')) {
            continue;
        }

        if (! str_contains($contents, 'beatrax-upload-transport')) {
            $missing[] = $path;
        }
    }

    expect($missing)->toBe([], "These layouts host posting UI but never tell the client which upload transport to use:\n  ".implode("\n  ", $missing));
});
