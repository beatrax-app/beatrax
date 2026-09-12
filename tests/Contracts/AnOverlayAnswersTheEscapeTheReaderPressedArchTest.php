<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupElement;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/an-overlay-answers-the-escape-the-reader-pressed.md
 */

// The palette is the one surface that has to choose between two of its own on
// one key — the token overlay first, then the palette — and a modifier cannot
// express an order. Its decision lives in palette.js, so the pin is re-checked
// against both the template that delegates and the file that decides.
const ESCAPE_AT_THE_WINDOW_PINNED = [
    'Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php' => [
        'reason' => 'two stacked surfaces settled in order by palette.js, which no attribute modifier can spell',
        'proves' => [
            'Modules/DevMode/Resources/views/livewire/command-palette-modal.blade.php' => '/x-on:keydown\.window="onKey\(/',
            'resources/js/palette.js' => "/e\\.key === 'Escape'/",
        ],
    ],
];

// 283 templates ship today, drawing 25 of these surfaces between them. Both
// floors sit far below, because a walk that stopped reading reports the same
// clean tree a walk that found nothing does.
const ESCAPE_AT_THE_WINDOW_TEMPLATE_FLOOR = 150;

const ESCAPE_AT_THE_WINDOW_SURFACE_FLOOR = 12;

const ESCAPE_AT_THE_WINDOW_OVERLAY_ROLE = '/\b(dialog|menu|listbox)\b/';

const ESCAPE_AT_THE_WINDOW_OUTSIDE_CLICK = '/^(x-on:click|@click)\.(.*\.)?(outside|away)(\.|$)/i';

const ESCAPE_AT_THE_WINDOW_GLOBAL_KEY = '/^(x-on:|@)key(down|up)(\.[a-z0-9_.-]*)?\.(window|document)(\.|$)/i';

const ESCAPE_AT_THE_WINDOW_NAMED_KEY = '/\.(escape|esc)(\.|$)/i';

const ESCAPE_AT_THE_WINDOW_COMMENT = '/\{\{--.*?--\}\}|<!--.*?-->/s';

it('answers the Escape a reader pressed from wherever they were standing (everyOverlayAnswersEscapeAtTheWindow)', function (): void {
    $templates = RepoTree::relativeFiles(RepoTree::EVERY_BLADE_VIEW);

    expect(count($templates))->toBeGreaterThan(
        ESCAPE_AT_THE_WINDOW_TEMPLATE_FLOOR,
        'The walk opened '.count($templates).' templates, which is a reader that stopped reading rather than a tree that got smaller.'
    );

    $offenders = [];
    $pinnedSeen = [];
    $surfaces = 0;

    foreach ($templates as $relative) {
        $found = escapeAtTheWindowScan((string) file_get_contents(RepoTree::root().'/'.$relative));
        $surfaces += $found['surfaces'];

        foreach ($found['offenders'] as $line) {
            if (array_key_exists($relative, ESCAPE_AT_THE_WINDOW_PINNED)) {
                $pinnedSeen[$relative] = true;

                continue;
            }

            $offenders[] = $relative.':'.$line;
        }
    }

    expect($surfaces)->toBeGreaterThanOrEqual(
        ESCAPE_AT_THE_WINDOW_SURFACE_FLOOR,
        'The walk found '.$surfaces.' overlay surfaces, which is a reader that stopped recognising them rather than a tree that stopped drawing them.'
    );

    expect($offenders)->toBe([], implode("\n", [
        'These surfaces open over the page and bind their dismiss key where the reader is not:',
        ...$offenders,
        '',
        'None of them moves focus into itself when it opens, so the reader is',
        'still standing on the control that opened it. A keydown listener on the',
        'panel hears only what is typed inside the panel, and a listener on the',
        'trigger stops hearing the moment the reader tabs into the surface — so',
        'the key that is supposed to be the way out reaches nothing.',
        '',
        'Bind it at the window, gated on the surface\'s own open state:',
        '',
        '    x-on:keydown.escape.window="open = false"',
        '',
        'on the element that owns that state, which for a trigger-and-panel pair',
        'is the wrapper holding both rather than either one of them.',
        '',
        'Where two of these can be open at once, the nearer one owns the key —',
        'decline it while the other is up rather than closing both.',
        '',
        'The pinned list holds one entry and is not a place to put a new site.',
    ]));

    expect(array_keys($pinnedSeen))->toBe(
        array_keys(ESCAPE_AT_THE_WINDOW_PINNED),
        'The pinned list names templates the walk no longer reports, so the exemption excuses nothing and reads as though it does.'
    );
});

it('holds each pinned surface to the mechanism that earned the exemption', function (): void {
    foreach (ESCAPE_AT_THE_WINDOW_PINNED as $relative => $pin) {
        foreach ($pin['proves'] as $path => $pattern) {
            $source = (string) file_get_contents(RepoTree::root().'/'.$path);

            expect(PatternScan::matches($pattern, $source))->toBeTrue(
                $relative.' is exempt because '.$pin['reason'].', and '.$path.' no longer shows it. '
                .'Without that, the exemption is an ordinary unreachable Escape wearing a pin.'
            );
        }
    }
});

it('reads an Escape bound at the window and one bound where the focus is not', function (): void {
    $answered = [
        '<div x-data><div x-show="open" role="dialog" x-on:keydown.escape.window="open = false"></div></div>',
        '<div x-on:keydown.escape.window="open = false"><div x-show="open" role="menu"></div></div>',
        '<div x-on:keydown.window="open && $event.key === \'Escape\' && (open = false)"><div x-show="open" role="listbox"></div></div>',
    ];
    $unanswered = [
        '<div x-data><div x-show="open" role="dialog" x-on:keydown.escape="open = false"></div></div>',
        '<div x-data><button x-on:keydown.escape="open = false"></button><div x-show="open" role="menu"></div></div>',
        '<div x-data><div x-show="open" x-on:click.outside="open = false"></div></div>',
        '<div x-on:keydown.window="onKey($event)"><div x-show="open" role="listbox"></div></div>',
    ];

    expect($answered)->not->toBe([], 'The answered probes were emptied, so nothing here proves a reachable Escape is recognised at all.')
        ->and($unanswered)->not->toBe([], 'The unanswered probes were emptied, so nothing here proves an unreachable one is reported.');

    foreach ($answered as $markup) {
        expect(escapeAtTheWindowScan($markup)['offenders'])->toBe([], $markup.' answers Escape from where the reader stands and must not be reported');
    }

    foreach ($unanswered as $markup) {
        expect(escapeAtTheWindowScan($markup)['offenders'])->toBe([1], $markup.' leaves the reader no key out and must be reported');
    }
});

it('reads a scrim as the backdrop it is, and a comment describing the rule as prose', function (): void {
    $scrim = '<div x-data><div x-show="open" role="dialog" aria-hidden="true" x-on:click.outside="open = false"></div></div>';
    $described = "{{-- x-on:click.outside with no escape is the defect --}}\n<div>ok</div>\n";
    $hidden = "<!-- a comment -->\n<div x-show=\"open\" role=\"dialog\"></div>\n";

    expect(escapeAtTheWindowScan($scrim))->toBe(['surfaces' => 0, 'offenders' => []], 'a backdrop hidden from the tree is not a surface a reader is standing in');
    expect(escapeAtTheWindowScan($described)['offenders'])->toBe([], 'a template explaining the rule is not a template breaking it');
    expect(escapeAtTheWindowScan($hidden)['offenders'])->toBe([2], 'blanking a comment must keep the lines under it at their own numbers');
});

/**
 * @return array{surfaces: int, offenders: list<int>}
 */
function escapeAtTheWindowScan(string $source): array
{
    $readable = escapeAtTheWindowReadable($source);
    $elements = MarkupSource::tags($readable);
    $surfaces = 0;
    $offenders = [];

    foreach ($elements as $element) {
        $ancestors = escapeAtTheWindowAncestors($elements, $element);

        if (! escapeAtTheWindowIsShown($element, $ancestors) || ! escapeAtTheWindowIsASurface($element)) {
            continue;
        }

        $surfaces++;

        if (! escapeAtTheWindowIsAnswered($element, $ancestors)) {
            $offenders[] = $element->line($readable);
        }
    }

    return ['surfaces' => $surfaces, 'offenders' => $offenders];
}

// Blanked rather than cut, so a surface below a comment keeps the line number a
// reader would open the file at.
function escapeAtTheWindowReadable(string $source): string
{
    return PatternScan::replaceCallback(
        ESCAPE_AT_THE_WINDOW_COMMENT,
        static fn (array $match): string => PatternScan::replace('/[^\n]/', ' ', $match[0]),
        $source,
    );
}

/**
 * @param  list<MarkupElement>  $elements
 * @return list<MarkupElement>
 */
function escapeAtTheWindowAncestors(array $elements, MarkupElement $element): array
{
    $ancestors = [];

    foreach ($elements as $candidate) {
        if ($candidate->inner === null || $candidate->offset >= $element->offset) {
            continue;
        }

        $opens = $candidate->offset + strlen($candidate->startTag);

        if ($element->offset >= $opens && $element->offset < $opens + strlen($candidate->inner)) {
            $ancestors[] = $candidate;
        }
    }

    return $ancestors;
}

/**
 * @param  list<MarkupElement>  $ancestors
 */
function escapeAtTheWindowIsShown(MarkupElement $element, array $ancestors): bool
{
    if ($element->hasAttribute('x-show')) {
        return true;
    }

    foreach ($ancestors as $ancestor) {
        if (strtolower($ancestor->name) === 'template' && ($ancestor->hasAttribute('x-if') || $ancestor->hasAttribute('x-show'))) {
            return true;
        }
    }

    return false;
}

function escapeAtTheWindowIsASurface(MarkupElement $element): bool
{
    if ($element->attribute('aria-hidden') === 'true') {
        return false;
    }

    // Bound as often as it is literal: the drawer is a dialog only below the
    // width where the stylesheet turns it into the static sidebar.
    $role = implode(' ', [
        $element->attribute('role') ?? '',
        $element->attribute(':role') ?? '',
        $element->attribute('x-bind:role') ?? '',
    ]);

    if (PatternScan::matches(ESCAPE_AT_THE_WINDOW_OVERLAY_ROLE, $role)) {
        return true;
    }

    return escapeAtTheWindowDismissesOnAnOutsideClick($element);
}

function escapeAtTheWindowDismissesOnAnOutsideClick(MarkupElement $element): bool
{
    foreach (array_keys($element->attributes()) as $name) {
        if (PatternScan::matches(ESCAPE_AT_THE_WINDOW_OUTSIDE_CLICK, $name)) {
            return true;
        }
    }

    return false;
}

/**
 * @param  list<MarkupElement>  $ancestors
 */
function escapeAtTheWindowIsAnswered(MarkupElement $element, array $ancestors): bool
{
    foreach ([$element, ...$ancestors] as $candidate) {
        if (escapeAtTheWindowBindsEscapeGlobally($candidate)) {
            return true;
        }
    }

    return false;
}

function escapeAtTheWindowBindsEscapeGlobally(MarkupElement $element): bool
{
    foreach ($element->attributes() as $name => $value) {
        if (! PatternScan::matches(ESCAPE_AT_THE_WINDOW_GLOBAL_KEY, $name)) {
            continue;
        }

        if (PatternScan::matches(ESCAPE_AT_THE_WINDOW_NAMED_KEY, $name) || str_contains($value, 'Escape')) {
            return true;
        }
    }

    return false;
}
