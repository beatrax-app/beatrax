<?php

declare(strict_types=1);

use Symfony\Component\Finder\Finder;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#the-upload-signal-missing-from-the-wizard-layout
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

/**
 * The CSRF token marks a layout that hosts real, posting UI — which is the
 * same set that can host a file input.
 *
 * @param  array<string, string>  $layouts  path => contents
 * @return array{posting: list<string>, missing: list<string>}
 */
function uploadSignalLayoutVerdict(array $layouts): array
{
    $posting = [];
    $missing = [];

    foreach ($layouts as $path => $contents) {
        if (! str_contains($contents, 'csrf-token')) {
            continue;
        }

        $posting[] = $path;

        if (! str_contains($contents, 'beatrax-upload-transport')) {
            $missing[] = $path;
        }
    }

    return ['posting' => $posting, 'missing' => $missing];
}

// Four layouts today, all four posting. The floors sit well under that: what
// they assert is that the Finder still reaches the layout tree and still reads
// a CSRF token in it, not that the tree has stopped growing.
it('finds the layouts at all, so a passing run means something', function (): void {
    $layouts = beatraxLayouts();

    expect(count($layouts))->toBeGreaterThanOrEqual(
        3,
        'the layout walk read almost nothing — the Finder roots are wrong, not the tree.',
    );

    expect(count(uploadSignalLayoutVerdict($layouts)['posting']))->toBeGreaterThanOrEqual(
        2,
        'no layout was read as hosting posting UI, so the rule below has nothing to hold and '
        .'reports a clean tree over a scan that found no CSRF token anywhere.',
    );
});

it('carries the upload-transport signal in every layout that carries a CSRF token', function (): void {
    $missing = uploadSignalLayoutVerdict(beatraxLayouts())['missing'];

    expect($missing)->toBe([], "These layouts host posting UI but never tell the client which upload transport to use:\n  ".implode("\n  ", $missing));
});

it('sees a posting layout with no transport signal, and leaves a non-posting one alone', function (): void {
    $verdict = uploadSignalLayoutVerdict([
        'planted/posting-without-signal.blade.php' => '<meta name="csrf-token" content="x">',
        'planted/posting-with-signal.blade.php' => '<meta name="csrf-token" content="x"><meta name="beatrax-upload-transport" content="base64">',
        'planted/static.blade.php' => '<p>nothing posts here</p>',
    ]);

    expect($verdict['posting'])->toBe(
        ['planted/posting-without-signal.blade.php', 'planted/posting-with-signal.blade.php'],
        'the reader no longer picks posting layouts out by their CSRF token.',
    );

    expect($verdict['missing'])->toBe(
        ['planted/posting-without-signal.blade.php'],
        'the reader no longer separates a posting layout that names the transport from one that does not.',
    );
});
