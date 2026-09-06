<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// The floor names every element that can be a button except the one an action
// is often marked up as. Measured on an iPhone 12 mini: the report library's
// own "Build a new report" — an <a> wearing .pill-btn-primary — answered a
// finger over 36px, while the identical control drawn as a <button> answered
// over 44. The header links beside a page title were worse: 16-23px, because
// nothing gave them the band .tap-link exists to give.
const TAP_FLOOR_BUTTON_SELECTOR = "label:has(> input[type='file']),";

// The two blocks that define the band, each anchored on the newline before its
// own indent. Without the newline, `strpos` for the shallower spelling lands
// INSIDE the deeper one — both selectors resolved to the phone-width block and
// the coarse-pointer block was never read at all.
const TAP_FLOOR_TAP_LINK_BLOCKS = [
    '@media (max-width: 767px)' => "\n        .tap-link {",
    '@media (pointer: coarse)' => "\n    .tap-link {",
];

// A "standalone action link beside a page title" is a role, not a shape a
// scanner can pick out of markup — every one of these is an <a> like any
// other. So the sites are named, and a device sweep is what puts one here.
const TAP_FLOOR_TITLE_ACTION_BLADES = [
    'Modules/Chains/Resources/views/livewire/chain-hints-queue.blade.php',
    'Modules/Chains/Resources/views/livewire/chain-review-queue.blade.php',
    'Modules/Counterparties/Resources/views/livewire/counterparty-index.blade.php',
    'Modules/DriftAlerts/Resources/views/livewire/drift-page.blade.php',
    'Modules/DriftAlerts/Resources/views/livewire/drift-watch-page.blade.php',
    'Modules/Forecasting/Resources/views/livewire/forecast-page.blade.php',
    'Modules/Notifications/Resources/views/livewire/notifications-page.blade.php',
    'Modules/Sync/Resources/views/livewire/devices-and-sync-settings-section.blade.php',
    'Modules/Tax/Resources/views/livewire/tax-page.blade.php',
];

it('puts a link drawn as a pill button on the same touch floor as a button', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    expect(strlen($css))->toBeGreaterThan(1000, 'resources/css/app.css read as all but empty — the path is wrong, not the stylesheet.');

    $selectors = CssRule::selectorListFor($css, TAP_FLOOR_BUTTON_SELECTOR);

    expect($selectors)->not->toBe('', 'No coarse-pointer floor lists the file-picker label.');

    // The rule's own selector list and its own block, rather than a 200-character
    // window after the selector: too short and a present declaration reads as
    // missing, too long and a neighbour's answers in its place.
    $missing = array_values(array_filter(
        ['.pill-btn-primary', '.pill-btn-ghost'],
        static fn (string $class): bool => ! str_contains($selectors, $class),
    ));

    expect($missing)->toBe([], 'The touch floor no longer lists: '.implode(', ', $missing).'. A link wearing one of those is a button everywhere but here.');

    expect(str_contains(CssRule::blockFor($css, TAP_FLOOR_BUTTON_SELECTOR), 'min-height: 44px;'))->toBeTrue(
        'The button floor exists but sets no 44px minimum, which is the whole of what it is for.',
    );
});

it('gives each page-title action link a device sweep found short its 44px band', function (): void {
    $absent = [];
    $without = [];

    foreach (TAP_FLOOR_TITLE_ACTION_BLADES as $blade) {
        if (! is_file(base_path($blade))) {
            $absent[] = $blade;

            continue;
        }

        if (! str_contains((string) file_get_contents(base_path($blade)), 'tap-link')) {
            $without[] = $blade;
        }
    }

    // A moved template reads as a missing band under a plain read, which sends
    // the next person to look for a class in a file that is not there.
    expect($absent)->toBe([], 'These templates are named here and no longer exist — repoint or remove the entry: '.implode(', ', $absent));

    expect($without)->toBe([], 'No 44px band on: '.implode(', ', $without));
});

// The switch track is 44x26 by design, and twenty callers draw it. Growing it
// to 44 tall would make a pill the size of a button, so it takes the band
// .tap-link takes instead -- same shape of problem, wide enough already and
// short only in height. Measured over 35px on an iPhone 12 mini before this.
it('gives the switch track the same band, since it is 44 wide and 26 tall', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $selector = ".tap-link::after,\n    .switch::after,\n    td > a:only-child::after {";
    $band = CssRule::blockFor($css, $selector);

    expect($band)->not->toBe('', 'The switch no longer shares the band .tap-link gets.');

    $missing = [];

    foreach ([
        'height: 44px;' => 'the band is no longer 44px tall, which is the floor it exists to give',
        // A band anchored to one edge takes its width from the control, which
        // is how a 42px "Hints →" and a 29px "Etos" got through.
        'min-width: 44px;' => 'the band takes its width from the control again, so a short label is a short target',
        'right: auto;' => 'anchored on both sides the min-width can never widen the band, which is the 42px "Hints →"',
    ] as $declaration => $consequence) {
        if (! str_contains($band, $declaration)) {
            $missing[] = $declaration.' — '.$consequence;
        }
    }

    expect($missing)->toBe([], "The shared band no longer holds the switch to 44px:\n  ".implode("\n  ", $missing));

    expect(str_contains(CssRule::blockFor($css, ".tap-link,\n    .switch,\n    td > a:only-child {"), 'position: relative;'))->toBeTrue(
        'Without a positioned control the band escapes to whatever ancestor happens to be positioned.',
    );
});

// An inline box split across two lines gives the band a containing block that
// is only its FIRST fragment, so the band is placed across the break and lands
// on neither line. Three call sites carry a hand-written `inline-block` for
// this; a device sweep on an iPhone 12 mini found the two nobody had spotted —
// the drift lifecycle panel at 2 of 4 band corners reachable and the recurring
// empty state at 0 of 4. An inline-block does not fragment.
it('keeps a wrapped link from fragmenting out of its own band, in both touch blocks', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $missing = [];

    foreach (TAP_FLOOR_TAP_LINK_BLOCKS as $atRule => $selector) {
        // Proves the two anchors reach two DIFFERENT blocks: read the wrong
        // one twice and this rule is half the rule it says it is.
        if (CssRule::atRuleEnclosing($css, $selector) !== $atRule) {
            $missing[] = $atRule.' — no .tap-link rule of its own sits inside it';

            continue;
        }

        if (! str_contains(CssRule::blockFor($css, $selector), 'display: inline-block;')) {
            $missing[] = $atRule.' — its .tap-link rule no longer sets display: inline-block';
        }
    }

    expect($missing)->toBe(
        [],
        'A touch block no longer stops .tap-link fragmenting, so a link that wraps is '
        ."placed across the break and answers a finger on neither line:\n  ".implode("\n  ", $missing),
    );
});

// Two bands centred 19px apart overlap, and the later one in the DOM paints
// over its neighbour's own text. Measured on an iPhone 12 mini at /forecast:
// a tap on the centre of "importing a statement" resolved to the link on the
// line below it. A target inline in a sentence is exempt from the floor, so a
// pair of them drops the band instead of fighting over it. Scoped to a
// paragraph: /chains puts two in a flex row, side by side, where they never
// fight and must keep theirs.
it('drops the band where two links share a parent and would cover each other', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $suppressions = substr_count($css, 'p:has(> a.tap-link ~ a.tap-link) > a.tap-link::after');

    expect($suppressions)->toBe(
        count(TAP_FLOOR_TAP_LINK_BLOCKS),
        'Each touch block that defines the 44px band must also drop it for a pair of '
        .'sibling links, or the later band covers the earlier link\'s own text.',
    );
});
