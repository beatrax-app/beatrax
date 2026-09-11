<?php

declare(strict_types=1);

use Modules\Core\Public\Support\CornerNotices;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// Seen on the desktop app on 2026-09-12 with two of them up at once: the inbox
// notice covered the receipt-conflict prompt's title and its question, leaving
// "future conflicts?" and the buttons "Use receipt" / "Keep statement" showing.
// The press writes users.receipt_conflict_resolution — how every later conflict
// resolves, chosen from a sentence tail.
//
// Three boxes were at that one address: the prompt at bottom-md, the notice at
// a hand-tuned bottom-24, the toast stack at bottom-4, all z-index 50 or above
// and none of them aware of the others, so document order decided which covered
// which. A `position: fixed` overlay has no sibling relationship with another
// one; a flex column has, and only the second arrangement makes "a control is
// clickable while its question is hidden" impossible rather than unlikely.
const CORNER_REGION_VIEW = 'Modules/Core/Resources/views/components/corner-notices.blade.php';

/**
 * @return list<string> one entry per element that anchors itself to the corner
 */
function cornerPinsIn(string $source, string $relativePath): array
{
    $pins = [];

    foreach (MarkupSource::tags($source) as $element) {
        if (CornerNotices::pinsToTheCorner($element->attribute('class'))) {
            $pins[] = $relativePath.':'.$element->line($source).' — <'.$element->name.' class="'.$element->attribute('class').'">';
        }
    }

    return $pins;
}

// @class([...]) builds the same attribute at runtime, where the scan above
// reads no `class` at all.
/**
 * @return list<string>
 */
function cornerPinsBuiltAtRuntimeIn(string $source, string $relativePath): array
{
    $pins = [];

    foreach (PatternScan::all('/@class\(\s*\[(.*?)\]\s*\)/s', $source)[1] as $body) {
        if (CornerNotices::pinsToTheCorner(str_replace(["'", '"', ',', '=>'], ' ', $body))) {
            $pins[] = $relativePath.' — @class(['.trim($body).'])';
        }
    }

    return $pins;
}

it('lets one view and no other pin an element to the bottom-right corner', function (): void {
    $views = RepoTree::relativeFiles(RepoTree::EVERY_BLADE_VIEW);

    expect(count($views))->toBeGreaterThan(
        150,
        'RepoTree returned '.count($views).' Blade views, which is too few to have read the tree.'
    );

    $offenders = [];

    foreach ($views as $relative) {
        if ($relative === CORNER_REGION_VIEW) {
            continue;
        }

        $source = (string) file_get_contents(RepoTree::root().'/'.$relative);
        $offenders = [...$offenders, ...cornerPinsIn($source, $relative), ...cornerPinsBuiltAtRuntimeIn($source, $relative)];
    }

    expect($offenders)->toBe([], implode("\n", [
        'These elements anchor themselves to the same corner as '.CORNER_REGION_VIEW.',',
        'which lays its occupants out in flow. Two boxes measuring their own gap from one',
        'screen edge are not a stack, and the taller of them loses whatever the shorter',
        'covers while the rest of it stays pressable:',
        ...$offenders,
        '',
        'Render into the region instead — @teleport('.CornerNotices::TELEPORT_TARGET.') from',
        'wherever the component lives — and give the element an order utility rather than a',
        'bottom offset of its own.',
    ]));
});

// The other half of the pair: the rule above is only worth something while the
// one exempted view still lays its occupants out so they cannot overlap.
it('stacks the region it exempts, rather than piling it', function (): void {
    $region = MarkupSource::elements(
        (string) file_get_contents(RepoTree::root().'/'.CORNER_REGION_VIEW),
        'div',
    );

    expect($region)->not->toBeEmpty(CORNER_REGION_VIEW.' draws no element at all.');

    $classes = (string) $region[0]->attribute('class');

    expect(CornerNotices::pinsToTheCorner($classes))->toBeTrue(
        'The region no longer pins to the corner, so the rule above exempts a view that is not the region.',
    );

    foreach (['flex', 'flex-col-reverse', 'gap-'] as $utility) {
        expect(str_contains($classes, $utility))->toBeTrue(
            'The region is missing `'.$utility.'`, so its occupants are no longer laid out apart from each other.',
        );
    }

    // The source, not a rendered page: the id is the constant every teleport
    // target in the tree is derived from, and a literal here would be a second
    // spelling of it that nothing compares.
    expect((string) $region[0]->attribute('id'))->toContain('CornerNotices::REGION_ID');
});

// An occupant that teleports somewhere else is an occupant of nothing, back on
// the screen on its own. Only Livewire's `@teleport` is read: Alpine's own
// `<template x-teleport>` carries the emoji-action tip to the body, which is a
// different surface with a different reason.
it('sends every Livewire @teleport in the tree into the region', function (): void {
    $elsewhere = [];

    foreach (RepoTree::relativeFiles(RepoTree::EVERY_BLADE_VIEW) as $relative) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$relative);

        foreach (PatternScan::all('/@teleport\(\s*([^)]*)\)/', $source)[1] as $target) {
            if (trim($target) !== 'CornerNotices::TELEPORT_TARGET') {
                $elsewhere[] = $relative.' — @teleport('.trim($target).')';
            }
        }
    }

    expect($elsewhere)->toBe([], implode("\n", [
        'A Livewire @teleport names a destination as a string, and a destination that does',
        'not exist drops the element out of the page in silence:',
        ...$elsewhere,
        '',
        'Name CornerNotices::TELEPORT_TARGET. A @teleport with another destination is',
        'welcome here once this rule learns to tell the two apart.',
    ]));
});

it('tells an element pinned to that corner from one pinned anywhere else', function (): void {
    expect(CornerNotices::pinsToTheCorner('fixed bottom-4 right-4'))->toBeTrue()
        ->and(CornerNotices::pinsToTheCorner('fixed bottom-md right-md z-50'))->toBeTrue()
        ->and(CornerNotices::pinsToTheCorner('fixed md:bottom-24 -right-4'))->toBeTrue()
        ->and(CornerNotices::pinsToTheCorner('fixed inset-x-0 bottom-0'))->toBeFalse()
        ->and(CornerNotices::pinsToTheCorner('fixed bottom-4 left-1/2'))->toBeFalse()
        ->and(CornerNotices::pinsToTheCorner('absolute bottom-4 right-4'))->toBeFalse()
        ->and(CornerNotices::pinsToTheCorner('order-1 w-full rounded-lg'))->toBeFalse()
        ->and(CornerNotices::pinsToTheCorner(null))->toBeFalse();
});

it('sees a pin a view builds out of an @class array', function (): void {
    $planted = '<div @class([\'fixed\', \'bottom-4\' => $a, \'right-4\' => $b])></div>';

    expect(cornerPinsBuiltAtRuntimeIn($planted, 'planted.blade.php'))->toHaveCount(1)
        ->and(cornerPinsBuiltAtRuntimeIn('<div @class([\'flex\', \'gap-2\'])></div>', 'planted.blade.php'))->toBe([]);
});
