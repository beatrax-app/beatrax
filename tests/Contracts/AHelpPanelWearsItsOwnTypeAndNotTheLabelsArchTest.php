<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;
use Tests\Helpers\CssRule;

// The panel renders in the top layer, which decides where it PAINTS and nothing
// about what it inherits: it is still a <div> written inside the label that
// opens it, and a label is the most heavily typed line on any screen. Every
// panel in the product shipped wearing that type. /budgets and the dashboard
// card sit inside `text-xs font-medium uppercase tracking-wide`, so three
// sentences of prose were drawn in CAPITALS at 0.3px of tracking; /chains,
// /reconcile and /recurring/review sit inside a 28px heading block, so theirs
// came out at font-weight 600 and -0.7px, a display face's negative tracking
// applied to 13px of body copy. Two properties had been noticed and put back --
// `text-wrap` and `hyphens` -- which is what makes this a shape rather than an
// oversight: the inheritance was known about and answered one property at a
// time.

/**
 * Everything a label can set and a paragraph of prose inherits. The panel
 * restates each one, so what it looks like is decided here and not by whatever
 * the next call site happens to be written inside.
 *
 * @var list<string>
 */
const HELP_PANEL_RESTATED_TYPE = [
    'color',
    'font-family',
    'font-size',
    'font-style',
    'font-variant-numeric',
    'font-weight',
    'hyphens',
    'letter-spacing',
    'line-height',
    'text-align',
    'text-indent',
    'text-transform',
    'text-wrap',
    'white-space',
    'word-spacing',
];

/**
 * The Tailwind spellings of those properties, so the rule above is re-checked
 * against what the tree actually writes above a mark rather than against a list
 * somebody once agreed with.
 *
 * @var array<string, string>
 */
const TYPE_UTILITY_PROPERTIES = [
    '~(^|\s)(uppercase|lowercase|capitalize|normal-case)(\s|$)~' => 'text-transform',
    '~(^|\s)(italic|not-italic)(\s|$)~' => 'font-style',
    '~(^|\s)font-(thin|extralight|light|normal|medium|semibold|bold|extrabold|black)(\s|$)~' => 'font-weight',
    '~(^|\s)font-(sans|serif|mono)(\s|$)~' => 'font-family',
    '~(^|\s)tracking-[a-z]+(\s|$)~' => 'letter-spacing',
    '~(^|\s)leading-[a-z0-9]+(\s|$)~' => 'line-height',
    '~(^|\s)text-(left|center|right|justify|start|end)(\s|$)~' => 'text-align',
    '~(^|\s)text-(xs|sm|base|lg|xl|[2-9]xl)(\s|$)~' => 'font-size',
    '~(^|\s)whitespace-[a-z-]+(\s|$)~' => 'white-space',
    '~(^|\s)indent-[a-z0-9.]+(\s|$)~' => 'text-indent',
    '~(^|\s)(tabular-nums|proportional-nums|ordinal|slashed-zero|lining-nums|oldstyle-nums)(\s|$)~' => 'font-variant-numeric',
];

/**
 * A mark whose parent is a component takes its type from that component's own
 * template, so the ancestors written at the call site are not the whole answer.
 * Each entry names the template that answers for the mark and a pattern that
 * re-reads the claim.
 *
 * @var array<string, array{reason: string, file: string, proves: string}>
 */
const HELP_PANEL_TYPE_FROM_A_COMPONENT = [
    'x-core::th' => [
        'reason' => 'the column-header component, which types every header it draws',
        'file' => 'Modules/Core/Resources/views/components/th.blade.php',
        'proves' => '~uppercase~',
    ],
    'x-slot:tip' => [
        'reason' => 'the tip slot of x-core::page-heading, which types the block the mark and the heading share',
        'file' => 'Modules/Core/Resources/views/components/page-heading.blade.php',
        'proves' => '~font-semibold~',
    ],
];

/**
 * Every element a mark is written inside, innermost first, with the class list
 * that types it. Read off the lexer rather than a tag-shaped pattern: four of
 * the six marks sit inside a start tag that spans five lines and one of them
 * carries a `=>` in an attribute, which is where `[^>]*` ends an element early.
 *
 * @return list<array{tag: string, class: string}>
 */
function typeAboveEachHelpMark(string $source): array
{
    if (! str_contains($source, '<x-core::help-tip')) {
        return [];
    }

    $elements = MarkupSource::tags($source);
    $above = [];

    foreach (MarkupSource::elements($source, 'x-core::help-tip') as $mark) {
        $ancestors = [];

        foreach ($elements as $element) {
            if ($element->inner === null || $element->offset >= $mark->offset) {
                continue;
            }

            $ends = $element->offset + strlen($element->startTag) + strlen($element->inner);

            if ($mark->offset < $ends) {
                $ancestors[] = ['tag' => $element->name, 'class' => $element->attribute('class') ?? ''];
            }
        }

        foreach (array_reverse($ancestors) as $ancestor) {
            $above[] = $ancestor;
        }
    }

    return $above;
}

/** @return list<string> */
function typePropertiesIn(string $classList): array
{
    $properties = [];

    foreach (TYPE_UTILITY_PROPERTIES as $pattern => $property) {
        if (PatternScan::matches($pattern, $classList)) {
            $properties[] = $property;
        }
    }

    return $properties;
}

it('states its own type in full rather than inheriting the label that opened it', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));
    $panel = CssRule::blockFor($css, '.help-tip-panel {');

    expect($panel)->not->toBe('', 'No rule in app.css declares .help-tip-panel, so nothing below read anything.');

    $missing = [];

    foreach (HELP_PANEL_RESTATED_TYPE as $property) {
        if (! PatternScan::matches('~(^|;|\s)'.preg_quote($property, '~').'\s*:~', $panel)) {
            $missing[] = $property;
        }
    }

    expect($missing)->toBe(
        [],
        ".help-tip-panel leaves these to whatever it is written inside:\n  ".implode("\n  ", $missing)
        ."\n\nThe panel is a paragraph of prose in a <div> inside a label, and a label is the most "
        .'heavily typed line on a screen. Every property a label can set is restated on the panel.'
    );
});

it('restates every treatment the tree actually writes above a mark', function (): void {
    $marks = 0;
    $leaking = [];
    $reached = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $source = (string) file_get_contents($path);

        if (! str_contains($source, '<x-core::help-tip')) {
            continue;
        }

        $relative = str_replace(RepoTree::root().'/', '', $path);

        foreach (typeAboveEachHelpMark($source) as $ancestor) {
            $classes = [$ancestor['class']];

            if (array_key_exists($ancestor['tag'], HELP_PANEL_TYPE_FROM_A_COMPONENT)) {
                $reached[$ancestor['tag']] = true;
                $classes[] = (string) file_get_contents(base_path(HELP_PANEL_TYPE_FROM_A_COMPONENT[$ancestor['tag']]['file']));
            }

            foreach ($classes as $classList) {
                foreach (typePropertiesIn($classList) as $property) {
                    $marks++;

                    if (in_array($property, HELP_PANEL_RESTATED_TYPE, true)) {
                        continue;
                    }

                    $leaking[] = $relative.' — <'.$ancestor['tag'].'> sets '.$property.', which .help-tip-panel does not restate';
                }
            }
        }
    }

    // A walk that read no typed ancestor at all would report every panel safe,
    // and this rule exists because six of six were not.
    expect($marks)->toBeGreaterThan(9, 'Read '.$marks.' typed ancestors above the marks in this tree, too few to have proved anything.');

    $reachedTags = array_keys($reached);
    $pinnedTags = array_keys(HELP_PANEL_TYPE_FROM_A_COMPONENT);
    sort($reachedTags);
    sort($pinnedTags);

    expect($reachedTags)->toBe($pinnedTags, 'A component pinned as the answer for a mark\'s type is no longer written above one: '
        .implode(', ', array_diff($pinnedTags, $reachedTags)));

    sort($leaking);

    expect(array_values(array_unique($leaking)))->toBe(
        [],
        "These reach the panel through inheritance and it does not put them back:\n  ".implode("\n  ", array_unique($leaking))
        ."\n\nAdd the property to .help-tip-panel and to HELP_PANEL_RESTATED_TYPE. The panel decides "
        .'what a help panel looks like; the label it is written inside does not.'
    );
});

it('still holds each pinned component to the template that types the mark inside it', function (): void {
    foreach (HELP_PANEL_TYPE_FROM_A_COMPONENT as $tag => $pin) {
        $source = (string) file_get_contents(base_path($pin['file']));

        expect(PatternScan::matches($pin['proves'], $source))
            ->toBeTrue($tag.' no longer reads as "'.$pin['reason'].'" in '.$pin['file']);
    }
});

it('reads a class list the way the browser would, and a bare one as setting nothing', function (): void {
    expect(typePropertiesIn('text-xs font-medium uppercase tracking-wide text-slate-500'))
        ->toBe(['text-transform', 'font-weight', 'letter-spacing', 'font-size'], 'the budgets label, which drew three sentences of prose in capitals');

    expect(typePropertiesIn('heading-with-tip text-2xl font-semibold tracking-tight text-slate-900'))
        ->toBe(['font-weight', 'letter-spacing', 'font-size'], 'the page-heading block, whose -0.7px reached a paragraph of body copy');

    expect(typePropertiesIn('mt-4 flex flex-wrap items-center gap-3'))
        ->toBe([], 'a layout row sets no type, and reading one as if it did would excuse nothing and blame everything');

    expect(typePropertiesIn('text-slate-500 dark:text-slate-400'))
        ->toBe([], 'a colour utility is not a size, however much text-slate-500 looks like text-sm');

    $nested = <<<'BLADE'
        <div class="text-2xl font-semibold">
            <span class="uppercase"><x-core::help-tip topic="x" /></span>
        </div>
        BLADE;

    expect(array_column(typeAboveEachHelpMark($nested), 'class'))
        ->toBe(['uppercase', 'text-2xl font-semibold'], 'the whole chain, innermost first: every one of them inherits into the panel');
});
