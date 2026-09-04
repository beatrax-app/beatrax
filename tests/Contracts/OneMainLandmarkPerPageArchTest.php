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
// about the landmark, not one being opened, and the walk steps over all three.
function mainLandmarkOpensOne(string $relativePath): bool
{
    $source = (string) file_get_contents(dirname(__DIR__, 2).'/'.$relativePath);

    return MarkupSource::elements($source, 'main') !== [];
}

it('opens a main landmark only where the view is the page root', function (): void {
    $offenders = [];

    foreach (mainLandmarkCandidateBlades() as $blade) {
        if (in_array($blade, MAIN_LANDMARK_PAGE_ROOTS, true)) {
            continue;
        }
        if (mainLandmarkOpensOne($blade)) {
            $offenders[] = $blade;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps every pinned page root real, so the list cannot outlive its files', function (): void {
    foreach (MAIN_LANDMARK_PAGE_ROOTS as $blade) {
        expect(file_exists(dirname(__DIR__, 2).'/'.$blade))->toBeTrue();
        expect(mainLandmarkOpensOne($blade))->toBeTrue();
    }
});
