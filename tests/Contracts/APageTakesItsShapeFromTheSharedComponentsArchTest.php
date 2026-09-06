<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;

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
//
// The step is now `py-6`. `py-12` was 51px of empty band above every title at
// the 17px coarse-pointer root, which is what the product owner saw; it was
// never chosen, it was the majority of a drift. The rhythm also has to be read
// off a page's ROOT element and not only off its `mx-auto` column: /tax splits
// the two across a wrapper and its column, so the band it draws was invisible
// to a rule that looked at `mx-auto` alone.
//
// The walk is the module tree. Eleven templates under resources/ are error
// documents and shared components rather than routed pages, and one of them
// draws a title of its own; the rules below say nothing about them.

// A surface excused from drawing its own title is not excused from where the
// band above it sits: nine of these ten never carried a rhythm of their own, so
// the two rules below name their own exemptions rather than inheriting this one.
/**
 * @return array<string, array{reason: string, proves: string}>
 */
function nonPageSurfaces(): array
{
    return [
        '/components/page-heading.blade.php' => [
            'reason' => 'the shared heading itself, which every page defers to, so it is the one file that has to draw an h1',
            'proves' => '/\$attributes->merge/',
        ],
        '/livewire/steps/' => [
            'reason' => 'a wizard step is a step inside a page rather than a page, and the onboarding flow keeps a scale of its own',
            'proves' => '/class="wiz-h1"/',
        ],
        '/views/pdf/' => [
            'reason' => 'a printed document carrying its own stylesheet, which no screen rule reaches',
            // Its title key rather than its title element: a tag-shaped pattern
            // in a guard is what AGuardThatReadsMarkupParsesItArchTest refuses.
            'proves' => '/tax::pdf\.title/',
        ],
        '/Modules/DevMode/' => [
            'reason' => 'the developer console, deliberately denser than the product it inspects and shown to nobody else',
            'proves' => '/dev::/',
        ],
        '/mobile-pairing-scan.blade.php' => [
            'reason' => 'the pairing flow runs full screen before the app shell exists, so it draws its own title and its own band',
            'proves' => '/mobile::pairing\./',
        ],
        '/sync-complete-screen.blade.php' => [
            'reason' => 'the last screen of the pairing flow, still outside the app shell that would have given it a heading',
            'proves' => '/mobile::sync_complete\./',
        ],
        '/setup-wizard.blade.php' => [
            'reason' => 'the wizard frame around those steps, which draws the pending step titles the steps themselves cannot',
            'proves' => '/wiz-step-pending-h1/',
        ],
        '/mobile-import-bootstrap.blade.php' => [
            'reason' => 'the first-run import screen, shown before the phone has a shell to hang a heading on',
            'proves' => '/mobile::import\./',
        ],
        '/sync-health-page.blade.php' => [
            'reason' => 'a diagnostics surface reached from the sync sheet rather than from the navigation, and sized for it',
            'proves' => '/sync::health\./',
        ],
    ];
}

// The one page container in the tree that is not a reading column: a full-bleed
// camera viewfinder, which is why its band is not the reading rhythm.
/**
 * @return array<string, array{reason: string, proves: string}>
 */
function nonPageColumns(): array
{
    return [
        '/mobile-pairing-scan.blade.php' => [
            'reason' => 'the pairing viewfinder is sized to the camera preview rather than to a column of text',
            'proves' => '/aspect-square/',
        ],
    ];
}

function pageRhythmStep(): string
{
    return '6';
}

/**
 * Every Blade template the module tree ships, the suite's own fixtures aside.
 *
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

        $found[] = $path;
    }

    sort($found);

    return $found;
}

/**
 * @param  array<string, array{reason: string, proves: string}>  $exemptions
 * @return list<string> the templates none of $exemptions names
 */
function pageTemplatesOutside(array $exemptions): array
{
    return array_values(array_filter(
        pageTemplates(),
        static function (string $path) use ($exemptions): bool {
            foreach (array_keys($exemptions) as $exempt) {
                if (str_contains($path, $exempt)) {
                    return false;
                }
            }

            return true;
        },
    ));
}

function pageDrawsItsOwnTitle(string $source): bool
{
    return MarkupSource::elements($source, 'h1') !== [];
}

/**
 * The vertical rhythm every `mx-auto` column in $source declares.
 *
 * @return list<string>
 */
function pageColumnRhythmIn(string $source): array
{
    $steps = [];

    foreach (PatternScan::all('/class="([^"]*\bmx-auto\b[^"]*)"/', $source)[1] as $classes) {
        foreach (PatternScan::all('/(?:^|\s)(?:sm:)?py-(\d+)/', $classes)[1] as $step) {
            $steps[] = $step;
        }
    }

    return $steps;
}

/**
 * The element $source opens with and the rhythm it declares, or null when the
 * template opens with no tag a class could sit on.
 *
 * @return array{tag: string, steps: list<string>}|null
 */
function pageRootRhythmIn(string $source): ?array
{
    $stripped = PatternScan::replace('/\{\{--.*?--\}\}/s', '', $source);

    if (preg_match('/<([a-zA-Z][a-zA-Z0-9:.-]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)>/', $stripped, $tag) !== 1) {
        return null;
    }

    if (preg_match('/class="([^"]*)"/', $tag[2], $class) !== 1) {
        return ['tag' => $tag[1], 'steps' => []];
    }

    return ['tag' => $tag[1], 'steps' => PatternScan::all('/(?:^|\s)(?:sm:)?py-(\d+)/', $class[1])[1]];
}

// A walk that opened nothing reports the same clean tree as a walk that found
// nothing. The module tree holds 268 templates; they declare a rhythm on 24
// centred columns and open 167 elements a class can sit on, and each floor sits
// far enough under its count that only a broken walk or reader trips it.
const PAGE_SHAPE_TEMPLATE_FLOOR = 150;

const PAGE_SHAPE_COLUMN_FLOOR = 10;

const PAGE_SHAPE_ROOT_FLOOR = 50;

it('does not let a page write its own h1', function (): void {
    $offenders = [];
    $templates = 0;

    foreach (pageTemplatesOutside(nonPageSurfaces()) as $path) {
        $templates++;

        if (pageDrawsItsOwnTitle((string) file_get_contents($path))) {
            $offenders[] = str_replace(base_path().'/', '', $path);
        }
    }

    expect($templates)->toBeGreaterThan(
        PAGE_SHAPE_TEMPLATE_FLOOR,
        'The walk opened '.$templates.' templates, so a clean answer here is a walk that read almost nothing.'
    );

    expect($offenders)->toBe(
        [],
        "These write an h1 by hand instead of using x-core::page-heading, so the page's title "
        ."can change size without any of its neighbours knowing:\n  ".implode("\n  ", $offenders)
    );
});

it('gives every page container the same vertical rhythm', function (): void {
    $offenders = [];
    $columns = 0;

    foreach (pageTemplatesOutside(nonPageColumns()) as $path) {
        foreach (pageColumnRhythmIn((string) file_get_contents($path)) as $step) {
            $columns++;

            if ($step !== pageRhythmStep()) {
                $offenders[] = str_replace(base_path().'/', '', $path)." carries py-{$step}";
            }
        }
    }

    sort($offenders);

    expect($columns)->toBeGreaterThan(
        PAGE_SHAPE_COLUMN_FLOOR,
        'The reader found '.$columns.' centred columns declaring a rhythm, which is too few to have read the tree.'
    );

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        'Every page container says py-'.pageRhythmStep().'. A page that says anything else puts its title at a '
        ."different height from every page either side of it:\n  ".implode("\n  ", $offenders)
    );
});

// This rule keeps no exemption of its own: none of the surfaces excused above
// draws a band on its root element, so excusing them here would excuse nothing.
it('reads the rhythm off a page root that is not the column', function (): void {
    $offenders = [];
    $roots = 0;

    foreach (pageTemplates() as $path) {
        if (str_contains($path, '/partials/') || str_contains($path, '/views/components/')) {
            continue;
        }

        $root = pageRootRhythmIn((string) file_get_contents($path));

        if ($root === null) {
            continue;
        }

        $roots++;

        foreach ($root['steps'] as $step) {
            if ($step !== pageRhythmStep()) {
                $offenders[] = str_replace(base_path().'/', '', $path)." opens <{$root['tag']}> carrying py-{$step}";
            }
        }
    }

    expect($roots)->toBeGreaterThan(
        PAGE_SHAPE_ROOT_FLOOR,
        'The root-element scan matched '.$roots.' templates, which means the tag pattern stopped reading rather than that the tree is clean.'
    );

    sort($offenders);

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        'A page that draws its band on a wrapper above its column still draws a band, and /tax drew '
        ."51px of one where the mx-auto rule could not see it:\n  ".implode("\n  ", $offenders)
    );
});

// The two maps are read one after the other rather than merged: they share the
// key /mobile-pairing-scan.blade.php, and a spread would drop one of the two
// reasons that path was granted without saying which.
it('still holds each exempted surface to the reason it was granted for', function (): void {
    $offenders = [];

    foreach ([nonPageSurfaces(), nonPageColumns()] as $pins) {
        foreach ($pins as $exempt => $pin) {
            $matched = array_values(array_filter(
                pageTemplates(),
                static fn (string $path): bool => str_contains($path, $exempt),
            ));

            if ($matched === []) {
                $offenders[] = $exempt.' names no template at all — '.$pin['reason'];

                continue;
            }

            $proving = array_filter(
                $matched,
                static fn (string $path): bool => PatternScan::matches($pin['proves'], (string) file_get_contents($path)),
            );

            if ($proving === []) {
                $offenders[] = $exempt.' no longer reads as '.$pin['reason']
                    .' ('.$pin['proves'].' matches none of its '.count($matched).' templates)';
            }
        }
    }

    expect($offenders)->toBe(
        [],
        'An exemption whose site has moved excuses whatever took its place, and reads as considered while it does it. '
        ."Re-read the surface and either move the pin or delete it:\n  ".implode("\n  ", $offenders)
    );
});

it('keeps no exempted surface that the rule would have let through anyway', function (): void {
    $idle = [];

    foreach (array_keys(nonPageSurfaces()) as $exempt) {
        $matched = array_filter(
            pageTemplates(),
            static fn (string $path): bool => str_contains($path, $exempt),
        );

        $drawing = array_filter(
            $matched,
            static fn (string $path): bool => pageDrawsItsOwnTitle((string) file_get_contents($path)),
        );

        if ($drawing === []) {
            $idle[] = $exempt;
        }
    }

    foreach (array_keys(nonPageColumns()) as $exempt) {
        $offending = array_filter(
            pageTemplates(),
            static fn (string $path): bool => str_contains($path, $exempt)
                && array_diff(pageColumnRhythmIn((string) file_get_contents($path)), [pageRhythmStep()]) !== [],
        );

        if ($offending === []) {
            $idle[] = $exempt;
        }
    }

    expect($idle)->toBe(
        [],
        'These exemptions hide a surface that no longer breaks the rule they excuse it from, so they excuse nothing '
        ."while still hiding everything else that path matches. Delete them:\n  ".implode("\n  ", $idle)
    );
});

// A guard that cannot go red says nothing, and all three verdicts above are read
// off one reader each. They are checked against the shapes they were written for
// rather than against the tree, so a rewrite cannot quietly stop finding them.
it('finds a page that draws its own title and leaves one deferring to the component alone', function (string $markup, bool $draws): void {
    expect(pageDrawsItsOwnTitle($markup))->toBe($draws);
})->with([
    'a hand-written title' => ['<div class="mx-auto py-6"><h1 class="text-xl">Reports</h1></div>', true],
    'a title with attributes on it' => ['<h1 id="page" class="text-lg">Reports</h1>', true],
    'the shared component' => ['<div class="mx-auto py-6"><x-core::page-heading>Reports</x-core::page-heading></div>', false],
    'a lesser heading' => ['<h2>Totals</h2>', false],
]);

it('reads the rhythm a centred column declares', function (string $markup, array $steps): void {
    expect(pageColumnRhythmIn($markup))->toBe($steps);
})->with([
    'the shared rhythm' => ['<div class="mx-auto max-w-5xl py-6">', ['6']],
    'a rhythm of its own' => ['<div class="mx-auto max-w-5xl py-12">', ['12']],
    'the responsive spelling' => ['<div class="mx-auto sm:py-8">', ['8']],
    'a column with no band' => ['<div class="mx-auto max-w-5xl">', []],
    'a band on something that is not a column' => ['<div class="max-w-5xl py-12">', []],
    'padding on one axis only' => ['<div class="mx-auto px-6">', []],
]);

it('reads the rhythm a page root declares', function (string $markup, ?array $root): void {
    expect(pageRootRhythmIn($markup))->toBe($root);
})->with([
    'a root carrying the shared rhythm' => ['<div class="py-6"><p>x</p></div>', ['tag' => 'div', 'steps' => ['6']]],
    'a root carrying its own' => ['<section class="space-y-4 py-12">', ['tag' => 'section', 'steps' => ['12']]],
    'a root with no class' => ['<div><p>x</p></div>', ['tag' => 'div', 'steps' => []]],
    'a comment above the root' => ['{{-- <div class="py-12"> --}}<div class="py-6">', ['tag' => 'div', 'steps' => ['6']]],
    'a template opening with no tag' => ['@php($x = 1)', null],
]);
