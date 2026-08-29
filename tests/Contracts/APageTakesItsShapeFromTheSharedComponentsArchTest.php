<?php

declare(strict_types=1);

// Twice in one round, a page that declared its own layout drifted away from the
// pages either side of it, and neither drift could fail a test.
//
// The h1: five pages set the size in a style attribute and two chose
// --text-xl, so /reports and /counterparties wore a heading visibly smaller
// than their neighbours; /data-devices sat at text-lg.
//
// The container: nineteen pages spelled the rhythm `py-12` and six spelled
// something else, and two carried a second column inside x-core::page-shell,
// which already owns one. On the phone that put the same title at 119, 129,
// 138, 146 or 170 pixels from the top depending on where you had navigated.

/**
 * Surfaces that are not routed pages and keep their own type on purpose: the
 * dev console is deliberately denser, and a wizard step is a step inside a page
 * rather than a page, so the onboarding and pairing flows keep their own scale.
 * The two heading components are here because they are what everything else
 * defers to, and the tax PDF is a printed document with its own stylesheet. Add a path only for a surface that is genuinely not a page.
 *
 * @return list<string>
 */
function nonPageSurfaces(): array
{
    return [
        '/components/page-heading.blade.php',
        '/components/page-header.blade.php',
        '/livewire/steps/',
        '/views/pdf/',
        '/Modules/DevMode/',
        '/mobile-pairing-scan.blade.php',
        '/sync-complete-screen.blade.php',
        '/setup-wizard.blade.php',
        '/mobile-welcome-screen.blade.php',
        '/mobile-import-bootstrap.blade.php',
        '/mobile-restore-from-backup.blade.php',
        '/sync-health-page.blade.php',
    ];
}

/**
 * @return list<string>
 */
function pageTemplates(): array
{
    $found = [];
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path('Modules')));

    foreach ($walk as $file) {
        $path = $file->getPathname();

        if (! str_ends_with($path, '.blade.php') || str_contains($path, '/tests/')) {
            continue;
        }

        foreach (nonPageSurfaces() as $exempt) {
            if (str_contains($path, $exempt)) {
                continue 2;
            }
        }

        $found[] = $path;
    }

    sort($found);

    return $found;
}

it('does not let a page write its own h1', function (): void {
    $offenders = [];

    foreach (pageTemplates() as $path) {
        if (preg_match('/<h1[\s>]/', (string) file_get_contents($path)) === 1) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($offenders)->toBe(
        [],
        "These write an h1 by hand instead of using x-core::page-heading, so the page's title "
        ."can change size without any of its neighbours knowing:\n  ".implode("\n  ", $offenders)
    );
});

it('gives every page container the same vertical rhythm', function (): void {
    $offenders = [];

    foreach (pageTemplates() as $path) {
        preg_match_all('/class="([^"]*\bmx-auto\b[^"]*)"/', (string) file_get_contents($path), $matches);

        foreach ($matches[1] as $classes) {
            preg_match_all('/(?:^|\s)(?:sm:)?py-(\d+)/', $classes, $paddings);

            foreach ($paddings[1] as $step) {
                if ($step !== '12') {
                    $offenders[] = str_replace(base_path().'/', '', $path)." carries py-{$step}";
                }
            }
        }
    }

    sort($offenders);

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        'Nineteen page containers say py-12. A page that says anything else puts its title at a '
        ."different height from every page either side of it:\n  ".implode("\n  ", $offenders)
    );
});
