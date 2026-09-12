<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/focus-that-moves-before-the-reader-asked.md
 */

// The one template that keeps the attribute, and the only one where it was
// never document-load focus: a closed popover is not focusable, so the
// load-time pass skips it and the popover focusing steps read it again when
// the reader opens the panel.
const FOCUS_ON_LOAD_PINNED = [
    'Modules/Core/Resources/views/components/help-tip.blade.php',
];

// 283 templates ship today. The floor sits far below that, because a walk that
// stopped reading reports the same clean tree a walk that found nothing does.
const FOCUS_ON_LOAD_TEMPLATE_FLOOR = 150;

// The attribute, not a name that contains it. `data-autofocus`, `x-on:autofocus`
// and `autofocusable` are different words and none of them moves focus.
const FOCUS_ON_LOAD_ATTRIBUTE = '/(?<![\w:.-])autofocus(?![\w-])/';

const FOCUS_ON_LOAD_COMMENT = '/\{\{--.*?--\}\}|<!--.*?-->/s';

it('leaves no template taking focus before the reader has asked for it', function (): void {
    $templates = RepoTree::relativeFiles(RepoTree::EVERY_BLADE_VIEW);

    expect(count($templates))->toBeGreaterThan(
        FOCUS_ON_LOAD_TEMPLATE_FLOOR,
        'The walk opened '.count($templates).' templates, which is a reader that stopped reading rather than a tree that got smaller.'
    );

    $offenders = [];
    $pinnedSeen = [];

    foreach ($templates as $relative) {
        foreach (focusOnLoadSites(RepoTree::root().'/'.$relative) as $line) {
            if (in_array($relative, FOCUS_ON_LOAD_PINNED, true)) {
                $pinnedSeen[] = $relative;

                continue;
            }

            $offenders[] = $relative.':'.$line;
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These take focus on document load, which drops a reader somewhere they did not ask to be:',
        ...$offenders,
        '',
        'Focus moving because the reader acted is the opposite of this and is',
        'correct: a modal that opens, a field a click revealed. Write that as',
        'focus at the moment the element appears —',
        '',
        '    x-init="$nextTick(() => $el.focus())"',
        '',
        'on an element the action inserts, or, where the element is in the',
        'document from the first paint, from the same event that reveals it:',
        '',
        '    x-on:modal-show.document="$event.detail && $event.detail.name === \'…\'',
        '        && $nextTick(() => $refs.….focus())"',
        '',
        'An element morphed in after load is the case worth checking before',
        'assuming the attribute worked: a document stops accepting autofocus',
        'candidates once focus has left its body, so the attribute on a field a',
        'click reveals has never fired in any browser.',
        '',
        'This stands in for a hosted accessibility rule that only scores lines a',
        'branch has touched, so the dashboard sees one of these at a time, long',
        'after the branch that wrote it merged. Anything failing here fails the',
        'hosted analysis the next time its line is edited.',
        '',
        'The pinned list holds one entry and is not a place to put a new site.',
        'It is pinned because the popover focusing steps read the attribute on a',
        'reader\'s own press, which is not what this rule is about.',
    ]));

    // The pin is a claim about another file, and a stale exemption is how a
    // guard goes quietly blind. If the attribute leaves help-tip, the entry
    // above has to leave with it.
    expect($pinnedSeen)->toBe(
        FOCUS_ON_LOAD_PINNED,
        'The pinned list names templates that no longer carry the attribute, so the exemption now excuses nothing and reads as though it does.'
    );
});

it('pins the exemption to the popover that earns it, not to the file that holds it', function (): void {
    foreach (FOCUS_ON_LOAD_PINNED as $relative) {
        $source = (string) file_get_contents(RepoTree::root().'/'.$relative);

        expect(PatternScan::matches('/(?<![\w:.-])popover(?![\w-])/', $source))->toBeTrue(
            $relative.' is exempt because a closed popover is not focusable and the attribute is read again when it opens. '
            .'Without a popover in it the exemption is an ordinary load-time focus wearing a pin.'
        );
    }
});

it('reads the attribute and leaves the words that merely contain it alone', function (): void {
    $focusing = ['<input autofocus />', '<x-core::form-field autofocus />', '<div popover autofocus>'];
    $innocent = ['<div data-autofocus>', '<div x-on:autofocus="x">', '<div class="autofocusable">', '<div :autofocus-hint="1">'];

    expect($focusing)->not->toBe([], 'The focusing probes were emptied, so nothing here proves the reader finds an attribute at all.')
        ->and($innocent)->not->toBe([], 'The innocent probes were emptied, so nothing here proves the reader stops at a word boundary.');

    foreach ($focusing as $markup) {
        expect(PatternScan::matches(FOCUS_ON_LOAD_ATTRIBUTE, $markup))->toBeTrue($markup.' moves focus on load and the reader must see it');
    }

    foreach ($innocent as $markup) {
        expect(PatternScan::matches(FOCUS_ON_LOAD_ATTRIBUTE, $markup))->toBeFalse($markup.' moves nothing and the reader must leave it alone');
    }
});

it('reads past a comment describing the attribute without reporting it', function (): void {
    $described = "{{-- autofocus is not used here --}}\n<input type=\"text\" />\n";
    $hidden = "<!-- autofocus -->\n<input autofocus />\n";

    expect(focusOnLoadSitesIn($described))->toBe([], 'a template explaining the rule is not a template breaking it');
    expect(focusOnLoadSitesIn($hidden))->toBe([2], 'blanking a comment must keep the lines under it at their own numbers');
});

/**
 * @return list<int> the line of every focusing attribute in the file at $path
 */
function focusOnLoadSites(string $path): array
{
    return focusOnLoadSitesIn((string) file_get_contents($path));
}

/**
 * @return list<int>
 */
function focusOnLoadSitesIn(string $source): array
{
    // Comments are blanked rather than cut, so a site below one keeps the line
    // number a reader would open the file at.
    $readable = PatternScan::replaceCallback(
        FOCUS_ON_LOAD_COMMENT,
        static fn (array $match): string => PatternScan::replace('/[^\n]/', ' ', $match[0]),
        $source,
    );

    $lines = [];

    foreach (explode("\n", $readable) as $index => $line) {
        if (PatternScan::matches(FOCUS_ON_LOAD_ATTRIBUTE, $line)) {
            $lines[] = $index + 1;
        }
    }

    return $lines;
}
