<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// The mark is lifted onto the cap height of the text beside it by an offset
// written in that text's own units, which only works while the mark and the
// label resolve to one font-size. A flex row breaks both halves at once: the
// item inherits the ROW's type rather than the label's, and `vertical-align`
// is ignored on a flex item, so the offset aims at the wrong text and the
// alignment it was correcting comes back. Measured at 375px: the mark sat
// 5.98px low beside a 28px heading, and on a heading that wrapped it was also
// squeezed from 18px to 14.56px, because a flex item with no basis gives way.

// A mark whose parent is a component takes its type from that component's own
// template, so the class list written at the call site is not what lays it out.
// Each entry names why, and a `proves` pattern re-run against the template that
// answers for the mark — a blanket "any x- parent" skip excused four sites and
// checked one.
const HELP_MARK_PARENT_PINS = [
    'x-core::th' => [
        'reason' => 'the column-header component, which declares one font-size for everything it draws',
        'file' => 'Modules/Core/Resources/views/components/th.blade.php',
        'proves' => '/\btext-(xs|sm|base|md|lg|xl)\b/',
    ],
    'x-slot:tip' => [
        'reason' => 'the tip slot of x-core::page-heading, which moves the mark onto a block of its own beside the heading rather than laying it out here',
        'file' => 'Modules/Core/Resources/views/components/page-heading.blade.php',
        'proves' => '/heading-with-tip/',
    ],
];

/**
 * Every blade that draws a help mark. The roots come from RepoTree: the rule is
 * about every view a reader is shown, and resources/ was outside the walk.
 *
 * @return list<string>
 */
function helpMarkBlades(): array
{
    $files = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        if (str_contains((string) file_get_contents($path), '<x-core::help-tip')) {
            $files[] = $path;
        }
    }

    return $files;
}

// The element each mark is written inside, read by tracking open tags rather
// than by taking a window before it: the row that used to hold a mark opens
// four tags above the one that used to be its parent.
/** @return list<array{line: int, tag: string, class: string}> */
function helpMarkParents(string $source, string $where): array
{
    $source = PatternScan::replaceCallback(
        '~\{\{--.*?--\}\}~s',
        static fn (array $match): string => str_repeat(' ', strlen($match[0])),
        $source,
    );

    $void = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'source', 'track', 'wbr'];
    $tags = PatternScan::setsWithOffsets(
        '~<(/?)([a-zA-Z][-a-zA-Z0-9:.]*)((?:"[^"]*"|\'[^\']*\'|[^>"\'])*)(/?)>~s',
        $source,
    );

    $stack = [];
    $parents = [];

    foreach ($tags as $tag) {
        [$whole, $offset] = $tag[0];
        $name = $tag[2][0];

        if ($name === 'x-core::help-tip') {
            $parent = end($stack);
            expect($parent)->not->toBeFalse($where.' writes a help mark outside every element, so nothing lays it out.');

            $parents[] = [
                'line' => substr_count(substr($source, 0, $offset), "\n") + 1,
                'tag' => $parent['tag'],
                'class' => $parent['class'],
            ];
        }

        if ($tag[4][0] === '/' || in_array(strtolower($name), $void, true)) {
            continue;
        }

        if ($tag[1][0] === '/') {
            array_pop($stack);

            continue;
        }

        $class = PatternScan::first('/class="([^"]*)"/', $tag[3][0]);
        $stack[] = ['tag' => $name, 'class' => $class[1] ?? ''];
    }

    return $parents;
}

it('never writes a help mark as a flex item beside the label it explains', function (): void {
    $blades = helpMarkBlades();
    $marks = 0;
    $offenders = [];
    $pinned = [];

    foreach ($blades as $path) {
        $relative = str_replace(RepoTree::root().'/', '', $path);

        foreach (helpMarkParents((string) file_get_contents($path), $relative) as $parent) {
            $marks++;

            if (array_key_exists($parent['tag'], HELP_MARK_PARENT_PINS)) {
                $pinned[$parent['tag']] = true;

                continue;
            }

            if (preg_match('/(^|\s)(flex|inline-flex|grid|inline-grid)(\s|$)/', $parent['class']) !== 1) {
                continue;
            }

            $offenders[] = $relative.':'.$parent['line']
                .' — <'.$parent['tag'].' class="'.$parent['class'].'">';
        }
    }

    // Four templates draw five marks today. A walk that found none of them
    // would report every mark as correctly placed.
    expect($marks)->toBeGreaterThan(2, 'Read '.$marks.' help marks across '.count($blades).' templates, too few to have proved anything.');

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "These marks are laid out by a row instead of by the line of text they explain:\n  ".implode("\n  ", $offenders)
        ."\n\nA component parent is pinned in HELP_MARK_PARENT_PINS with the template that answers for the mark; "
        .'a new one is pinned there or the mark moves out of the row.'
    );

    $reached = array_keys($pinned);
    $granted = array_keys(HELP_MARK_PARENT_PINS);
    sort($reached);
    sort($granted);

    // A pinned parent nothing writes any more excuses nothing, and would sit
    // here excusing whatever came to be written under that tag later.
    expect($reached)->toBe($granted, 'A pinned help-mark parent is no longer reached by the walk that granted it: '
        .implode(', ', array_diff($granted, $reached)));
});

it('still holds each pinned parent to the template that answers for the mark inside it', function (): void {
    foreach (HELP_MARK_PARENT_PINS as $tag => $pin) {
        $source = (string) file_get_contents(base_path($pin['file']));

        expect(PatternScan::matches($pin['proves'], $source))
            ->toBeTrue($tag.' no longer reads as "'.$pin['reason'].'" in '.$pin['file']);
    }
});

it('reads the element a mark is written inside, not the row that opened four tags above it', function (): void {
    $nested = <<<'BLADE'
        <div class="flex items-center gap-2">
            <h2 class="text-lg">Label</h2>
            <span class="text-sm">
                <x-core::help-tip topic="x" />
            </span>
        </div>
        BLADE;

    expect(array_column(helpMarkParents($nested, 'a.blade.php'), 'tag'))
        ->toBe(['span'], 'the parent is the element the mark sits inside, and the flex row four tags up is not it');

    $flexed = '<div class="flex items-center"><x-core::help-tip topic="x" /></div>';

    expect(helpMarkParents($flexed, 'a.blade.php'))
        ->toBe([['line' => 1, 'tag' => 'div', 'class' => 'flex items-center']], 'the flex parent is the whole defect, with the class list the failure names');
});
