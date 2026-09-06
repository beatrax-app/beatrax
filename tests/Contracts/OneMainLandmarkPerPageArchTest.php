<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

// layouts.app already wraps the page in a <main>, so a view mounted inside it
// that opens its own ships two unlabelled "main" regions for a screen reader to
// choose between, and a nesting the HTML spec does not allow.
//
// x-core::page-shell was introduced to settle this for the seventeen route
// views that wrote the nest out by hand. Nothing enforced it afterwards, so two
// views kept theirs: /transactions/{id} carried two mains on every visit, and
// the command palette added a second one to whatever page it opened over.
//
// A view that IS the page root legitimately owns the landmark. Those are named
// below rather than detected, because "is this rendered inside layouts.app"
// is not a property of the file.
const MAIN_LANDMARK_PAGE_ROOTS = [
    // Its own layout (onboarding::layouts.app-wizard), which has no <main>.
    'Modules/Onboarding/Resources/views/livewire/setup-wizard.blade.php',
    // Whole documents: these open <html> themselves.
    'resources/views/components/errors/beatrax-error.blade.php',
    'mobile-app/resources/views/components/errors/beatrax-error.blade.php',
];

/** @return list<string> repo-relative blade paths outside views/layouts/ */
function mainLandmarkCandidateBlades(): array
{
    $root = dirname(__DIR__, 2);
    $found = [];

    foreach (['Modules/*/Resources/views', 'resources/views', 'mobile-app/resources/views'] as $glob) {
        foreach (glob($root.'/'.$glob, GLOB_ONLYDIR) ?: [] as $dir) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
            foreach ($iterator as $file) {
                $path = (string) $file;
                if (! str_ends_with($path, '.blade.php') || str_contains($path, '/views/layouts/')) {
                    continue;
                }
                $found[] = substr($path, strlen($root) + 1);
            }
        }
    }

    sort($found);

    return array_values(array_unique($found));
}

// A <main> named in a blade comment, an HTML comment or a @php block is prose
// about the landmark, not one being opened, and the reader steps over all three.
function mainLandmarkSourceOpensOne(string $source): bool
{
    return MarkupSource::elements($source, 'main') !== [];
}

function mainLandmarkOpensOne(string $relativePath): bool
{
    return mainLandmarkSourceOpensOne((string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath));
}

it('opens a main landmark only where the view is the page root', function (): void {
    $candidates = mainLandmarkCandidateBlades();

    expect(count($candidates))->toBeGreaterThan(150, 'The Blade walk found almost nothing, so a clean answer below is the walk being broken rather than the views being right.');

    $offenders = [];

    foreach ($candidates as $blade) {
        if (in_array($blade, MAIN_LANDMARK_PAGE_ROOTS, true)) {
            continue;
        }
        if (mainLandmarkOpensOne($blade)) {
            $offenders[] = $blade;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'layouts.app already wraps the page in a <main>, so a view mounted inside it that',
        'opens its own ships two unlabelled landmarks for a screen reader to choose between.',
        'Use x-core::page-shell, or add the view to MAIN_LANDMARK_PAGE_ROOTS with the reason',
        'it is a page root written above the line. Offenders:',
        ...$offenders,
    ]));
});

it('keeps every pinned page root real, so the list cannot outlive its files', function (): void {
    $stale = [];

    foreach (MAIN_LANDMARK_PAGE_ROOTS as $blade) {
        if (! file_exists(dirname(__DIR__, 2).'/'.$blade)) {
            $stale[] = $blade.'  (file is gone)';

            continue;
        }
        if (! mainLandmarkOpensOne($blade)) {
            $stale[] = $blade.'  (no longer opens a <main>, so the exemption excuses nothing)';
        }
    }

    expect($stale)->toBe([], implode("\n", [
        'A pinned page root has stopped being what earned it the exemption. Delete the line',
        'so the scan covers the file again, or move the pin to wherever the landmark went:',
        ...$stale,
    ]));
});

it('reads a landmark a view opens and not one it only writes about', function (): void {
    $opens = "<x-core::page-shell>\n<main class=\"px-4\">content</main>\n</x-core::page-shell>";
    // The three near misses the reader has to step over, all in one view: the
    // landmark named in a Blade comment, in an HTML comment, and in a @php
    // block that builds the string rather than emitting the element.
    $describes = "{{-- wraps a <main> --}}\n<!-- <main> -->\n@php \$tag = '<main>'; @endphp\n<div>content</div>";

    expect(mainLandmarkSourceOpensOne($opens))->toBeTrue('The reader stopped seeing a <main> a view really opens.')
        ->and(mainLandmarkSourceOpensOne($describes))->toBeFalse('The reader is counting prose about the landmark as one being opened.');
});
