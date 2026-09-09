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

// A control cannot contain the mark that explains it. A help-tip inside a
// <button> is one interactive element nested in another: no browser resolves
// it and both controls break. Those labels carry the mark immediately after
// the control instead, and this reads the gap to make sure "immediately" is
// true rather than assumed.
function aHelpMarkFollows(string $source, MarkupElement $element, string $key): bool
{
    $after = substr($source, $element->offset + strlen($element->startTag) + strlen((string) $element->inner));

    foreach (MarkupSource::elements($after, 'x-core::help-tip') as $mark) {
        $label = PatternScan::first('~Lang::get\\(\'([^\']+)\'~', $mark->attribute(':label') ?? '');

        if ($label === [] || $label[1] !== $key) {
            continue;
        }

        return nothingAReaderReadsLiesBetween(substr($after, 0, $mark->offset));
    }

    return false;
}

// Closing tags, a Blade condition and a non-breaking space are what separate a
// label from the mark beside it. Prose is not, and a mark a paragraph later is
// not beside anything.
function nothingAReaderReadsLiesBetween(string $between): bool
{
    $text = MarkupSource::text($between);
    $text = str_replace(['&nbsp;', "\u{00a0}"], '', $text);
    $text = PatternScan::replace('~@\\w+\\s*(\\([^()]*\\))?~', '', $text);

    return trim($text) === '';
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

    // The floor is the count itself, not a token above zero. This guard only
    // asks whether a label is bare on screens OTHER than the one explaining
    // it, so deleting the last panel for a key takes every bare drawing of it
    // out of scope and the guard reports clean. Adding a panel raises this
    // number; removing one has to be the deliberate act of lowering it.
    expect(count($keys))->toBeGreaterThanOrEqual(7, 'Read '.count($keys).' labels carrying a help panel, fewer than the tree had when this floor was set. A deleted panel silences every bare drawing of its label.');

    $bare = [];
    $drawn = 0;

    foreach ($keys as $key => $explainedIn) {
        foreach ($sources as $relative => $source) {
            foreach (elementsDrawingTheLabel($source, $key) as $element) {
                $drawn++;

                if (aHelpMarkIsWrittenIn($element) || aHelpMarkFollows($source, $element, $key)) {
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
        ->and($verdict($beside))->toBe([['p', false]], 'the mark next door is not written IN the label, which is what this reader answers; whether it is still beside it is aHelpMarkFollows()\'s question')
        ->and($verdict($bare))->toBe([['p', false]], 'the whole defect: the label with nothing beside it');

    expect(labelsAHelpPanelExplains(['a.blade.php' => $explained]))->toBe([$key => 'a.blade.php'], 'the panel names the key it explains through its own :label argument');
});

// The attribute a pattern would have had to find. `:label` is not an HTML
// attribute name, its value carries quotes and parentheses of its own, and at
// four of the six call sites the tag it sits in spans five lines.
// The shape the lock screen is forced into. Its label lives inside a <button>,
// and a help-tip written in there would be one interactive element nested in
// another -- so the mark goes immediately after the control, and "immediately"
// is the whole of what makes it reachable.
it('accepts a mark beside a control that cannot contain it, and only while nothing readable intervenes', function (): void {
    $key = 'auth::lock_screen.forgot_pin';
    $mark = '<x-core::help-tip topic="forgot-pin" :label="Lang::get(\''.$key.'\')" :body="$body" />';
    $label = '{{ Lang::get(\''.$key.'\') }}';
    $button = '<form><button type="submit">'.$label.'</button></form>';

    $gated = $button.'@if ($due)&nbsp;'.$mark.'@endif';
    $glued = $button.$mark;
    $afterProse = $button.'<p>Something else entirely a reader stops to read.</p>'.$mark;
    $otherKey = $button.'&nbsp;<x-core::help-tip topic="t" :label="Lang::get(\'auth::lock_screen.sign_out\')" :body="$body" />';
    $none = $button;

    $reachable = static function (string $source) use ($key): bool {
        $drawing = elementsDrawingTheLabel($source, $key);

        expect($drawing)->toHaveCount(1, 'the reader should find the one control drawing the label');

        return aHelpMarkFollows($source, $drawing[0], $key);
    };

    expect($reachable($gated))->toBeTrue('a closing tag, a Blade condition and a non-breaking space are not things a reader reads')
        ->and($reachable($glued))->toBeTrue('the mark straight after the control is the same shape without the gate')
        ->and($reachable($afterProse))->toBeFalse('a mark on the far side of a sentence is not beside the label')
        ->and($reachable($otherKey))->toBeFalse('a mark explaining a different label explains nothing about this one')
        ->and($reachable($none))->toBeFalse('the defect itself: a control naming a thing with no way to read the answer');

    expect(aHelpMarkIsWrittenIn(elementsDrawingTheLabel($gated, $key)[0]))
        ->toBeFalse('and none of this is reachable through containment, which is why the second reader exists');
});

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
