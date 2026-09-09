<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// A page's own left margin is not a per-screen decision: a reader moving
// between screens sees the heading move, and nothing on the screen explains
// why. /calendar was `px-1 sm:px-4` because the seven-column month grid wanted
// the pixels, and it took the title and the prose with it — measured on a
// Galaxy A51, the heading sat at x=4 where every other page's sat at x=16.
//
// The grid can still have the width. It takes it back with a negative margin
// of its own rather than by moving the page.

/** The base-breakpoint horizontal padding a page container declares, if any. */
function pageGutterOf(string $classes): ?string
{
    $found = PatternScan::all('/(?<![a-z:-])px-([a-z0-9.\[\]-]+)/', $classes);

    return $found[1][0] ?? null;
}

/**
 * Every page-root container in the tree: `mx-auto max-w-*` is what a page
 * shell is spelled as here, and the ones that pad horizontally at all are the
 * set this rule is about — a wrapper with no px-* is not choosing a margin.
 *
 * @return array<string, string> "path: classes" => base gutter
 */
function pageGutters(): array
{
    $gutters = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        foreach (PatternScan::all('/class="(mx-auto max-w-[^"]*)"/', $source)[1] as $classes) {
            $gutter = pageGutterOf($classes);

            if ($gutter === null) {
                continue;
            }

            $gutters[str_replace(RepoTree::root().'/', '', $path).': '.$classes] = $gutter;
        }
    }

    return $gutters;
}

it('gives every page the same margin at phone width', function (): void {
    $gutters = pageGutters();

    expect(count($gutters))->toBeGreaterThan(10, 'The walk found almost no page containers, so a clean answer below is the walk being broken.');

    $offenders = array_keys(array_filter($gutters, static fn (string $gutter): bool => $gutter !== '4'));

    sort($offenders);

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['A page container declares a phone gutter that is not px-4:'],
        $offenders,
        [
            '',
            'Every screen in the application starts its heading at the same margin,',
            'and a page that narrows the gutter to fit one wide element moves the',
            'title and the prose with it. Widen the element instead — the month',
            'grid takes its pixels back with `-mx-3 sm:mx-0` on its own frame.',
            'A larger breakpoint may still widen the gutter (`sm:px-8` and the',
            'rest); this rule is about where a phone starts.',
        ],
    )));
});

// Both directions, because a reader of the rule cannot tell from a clean run
// whether it looked at the base utility or at whatever px-* came first.
it('reads the base gutter and not a breakpoint-prefixed one', function (): void {
    $narrow = sprintf('px-%d', 1);

    expect(pageGutterOf('mx-auto max-w-7xl '.$narrow.' sm:px-4 py-6'))->toBe('1')
        ->and(pageGutterOf('mx-auto max-w-3xl px-4 py-6 space-y-6 sm:px-8'))->toBe('4')
        ->and(pageGutterOf('mx-auto max-w-5xl space-y-2'))->toBeNull()
        ->and(pageGutterOf('mx-auto max-w-md sm:px-8'))->toBeNull('a container that pads only above a breakpoint chooses no phone gutter');
});
