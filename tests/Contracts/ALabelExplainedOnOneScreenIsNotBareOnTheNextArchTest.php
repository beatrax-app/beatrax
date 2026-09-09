<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupElement;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// A help panel is opened from beside the label it explains, so which readers can
// reach it is decided by where that label is drawn -- and a label is drawn on
// more screens than the one whose author wrote the panel. "Ready to assign" is
// the arithmetic result the whole envelope feature turns on: /budgets explained
// it and the dashboard card that greets every reader with the same three words
// did not, so the answer existed and the screen most people read never offered
// it. Nothing failed. The page returned 200, the copy was already translated
// into all twenty-six locales, and the panel was one attribute away.

/**
 * The lang key each help panel names as the thing it explains, mapped to the
 * template that carries the panel.
 *
 * @param  array<string, string>  $sources
 * @return array<string, string>
 */
function labelsAHelpPanelExplains(array $sources): array
{
    $keys = [];

    foreach ($sources as $relative => $source) {
        if (! str_contains($source, '<x-core::help-tip')) {
            continue;
        }

        foreach (MarkupSource::elements($source, 'x-core::help-tip') as $mark) {
            $label = PatternScan::first('~Lang::get\(\'([^\']+)\'~', $mark->attribute(':label') ?? '');

            if ($label === []) {
                continue;
            }

            $keys[$label[1]] ??= $relative;
        }
    }

    ksort($keys);

    return $keys;
}

/**
 * The elements that draw the key, narrowed to the nearest one around each
 * drawing. `text()` is what makes the question answerable: it strips every tag
 * and with it every attribute, so the panel's own `:label` argument -- which
 * names this same key on every explained site -- is not read as a drawing of
 * it, and no offset arithmetic has to tell the two apart.
 *
 * @return list<MarkupElement>
 */
function elementsDrawingTheLabel(string $source, string $key): array
{
    $needle = 'Lang::get(\''.$key.'\'';

    // A file that does not hold the key at all holds it in neither a text node
    // nor an attribute, so the lexer is spared two hundred and seventy-eight
    // templates it would answer nothing about.
    if (! str_contains($source, $needle)) {
        return [];
    }

    $drawing = [];

    foreach (MarkupSource::tags($source) as $element) {
        if ($element->inner !== null && str_contains(MarkupSource::text($element->inner), $needle)) {
            $drawing[] = $element;
        }
    }

    return array_values(array_filter(
        $drawing,
        static fn (MarkupElement $element): bool => ! aNearerElementDrawsIt($drawing, $element),
    ));
}

/**
 * @param  list<MarkupElement>  $drawing
 */
function aNearerElementDrawsIt(array $drawing, MarkupElement $element): bool
{
    $ends = $element->offset + strlen($element->startTag) + strlen((string) $element->inner);

    foreach ($drawing as $other) {
        if ($other !== $element && $other->offset > $element->offset && $other->offset < $ends) {
            return true;
        }
    }

    return false;
}

// The mark anywhere inside the element the label is written in, rather than
// glued to it: x-core::page-heading takes its mark through a slot several lines
// below the heading text, and that is the shape three of the six marks use.
function aHelpMarkIsWrittenIn(MarkupElement $element): bool
{
    return MarkupSource::elements((string) $element->inner, 'x-core::help-tip') !== [];
}

it('never draws a label bare on one screen while another screen explains it', function (): void {
    $sources = [];

    foreach (RepoTree::files(RepoTree::EVERY_BLADE_VIEW) as $path) {
        $sources[str_replace(RepoTree::root().'/', '', $path)] = (string) file_get_contents($path);
    }

    // A walk that opened a handful of templates would report the whole tree
    // clean, which is the failure this guard exists to make impossible.
    expect(count($sources))->toBeGreaterThan(200, 'Opened '.count($sources).' Blade views, too few to be the tree a reader is shown.');

    $keys = labelsAHelpPanelExplains($sources);

    expect(count($keys))->toBeGreaterThan(2, 'Read '.count($keys).' labels carrying a help panel; the tree has five and a reader of none proves nothing.');

    $bare = [];
    $drawn = 0;

    foreach ($keys as $key => $explainedIn) {
        foreach ($sources as $relative => $source) {
            foreach (elementsDrawingTheLabel($source, $key) as $element) {
                $drawn++;

                if (aHelpMarkIsWrittenIn($element)) {
                    continue;
                }

                $bare[] = $relative.':'.$element->line($source).' — <'.$element->name.'> draws '.$key
                    .', explained in '.$explainedIn;
            }
        }
    }

    expect($drawn)->toBeGreaterThan(4, 'Found '.$drawn.' places drawing an explained label, fewer than the templates that carry the panels.');

    sort($bare);

    expect($bare)->toBe(
        [],
        "These screens name a thing another screen explains, and offer no way to read the answer:\n  ".implode("\n  ", $bare)
        ."\n\nThe copy already exists in all twenty-six locales, so the fix is the mark beside the label, "
        .'written inside the label\'s own element. If the second screen genuinely explains it some other way, '
        .'the label there is a different string.'
    );
});

it('reads a mark inside the label\'s own element, and not one in the element beside it', function (): void {
    $key = 'budgets::messages.ready.label';
    $mark = '<x-core::help-tip topic="x" :label="Lang::get(\''.$key.'\')" :body="$body" />';
    $label = '{{ Lang::get(\''.$key.'\') }}';

    $explained = '<div class="card"><div class="text-xs">'.$label.'&nbsp;'.$mark.'</div></div>';
    $throughASlot = '<x-core::page-heading>'."\n".$label."\n".'<x-slot:tip>'.$mark.'</x-slot:tip>'."\n".'</x-core::page-heading>';
    $beside = '<div class="card"><p class="text-xs">'.$label.'</p><span>'.$mark.'</span></div>';
    $bare = '<div class="card"><p class="text-xs">'.$label.'</p></div>';

    $verdict = static fn (string $source): array => array_map(
        static fn (MarkupElement $element): array => [$element->name, aHelpMarkIsWrittenIn($element)],
        elementsDrawingTheLabel($source, $key),
    );

    expect($verdict($explained))->toBe([['div', true]], 'a mark glued to the label is the shape every call site uses')
        ->and($verdict($throughASlot))->toBe([['x-core::page-heading', true]], 'the tip slot of a page heading carries the mark for the heading beside it')
        ->and($verdict($beside))->toBe([['p', false]], 'a mark in the element next door explains nothing and reads as bare')
        ->and($verdict($bare))->toBe([['p', false]], 'the whole defect: the label with nothing beside it');

    expect(labelsAHelpPanelExplains(['a.blade.php' => $explained]))->toBe([$key => 'a.blade.php'], 'the panel names the key it explains through its own :label argument');
});

// The attribute a pattern would have had to find. `:label` is not an HTML
// attribute name, its value carries quotes and parentheses of its own, and at
// four of the six call sites the tag it sits in spans five lines.
it('reads the key off the panel and not off the label it is written beside', function (): void {
    $wrapped = <<<'BLADE'
        <x-core::th align="left">{{ Lang::get('budgets::messages.table.if_overspent') }}&nbsp;<x-core::help-tip
            topic="budgets-overspend"
            :label="Lang::get('budgets::messages.table.if_overspent')"
            :body="Lang::get('budgets::help.if_overspent', [
                'reduce' => Lang::get('budgets::messages.overspend.reduce'),
                'carry' => Lang::get('budgets::messages.overspend.carry'),
            ])"
        /></x-core::th>
        BLADE;

    expect(labelsAHelpPanelExplains(['b.blade.php' => $wrapped]))
        ->toBe(['budgets::messages.table.if_overspent' => 'b.blade.php'], 'the :body argument holds a => inside a tag, which is where a [^>]* pattern ends it');

    expect(array_map(
        static fn (MarkupElement $element): string => $element->name,
        elementsDrawingTheLabel($wrapped, 'budgets::messages.table.if_overspent'),
    ))->toBe(['x-core::th'], 'one drawing, not two: the same key inside the panel is an attribute and not a drawing');
});
