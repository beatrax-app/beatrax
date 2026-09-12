<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-resource-started-on-an-element-that-outlived-it
 */

/**
 * The calls that hand back something the DOM does not own. Removing the element
 * they were started from stops nothing: the timer keeps ticking, the stream
 * keeps reconnecting, and both still hold `$wire` for the component that is no
 * longer on screen.
 *
 * @var list<string>
 */
const RESOURCE_STARTERS = [
    'setInterval(',
    'new EventSource(',
    'new WebSocket(',
    'new MutationObserver(',
    'new ResizeObserver(',
    'new IntersectionObserver(',
    'window.addEventListener(',
    'document.addEventListener(',
];

/**
 * The modules `resources/js/app.js` imports an Alpine factory from. One file is
 * one element-scoped component there, so the whole-file question the Blade half
 * asks of a start tag is the right question to ask of the file.
 *
 * `app.js` itself is not among them and cannot be: it also carries the page's
 * own machinery — the submit delegate, the theme watcher, the wizard's back
 * gesture — which binds to the document on purpose and has no element to be
 * stopped with.
 *
 * @return array<string, string> name Alpine.data() was given => the module it came from
 */
function alpineFactoryModules(): array
{
    $entry = RepoTree::root().'/resources/js/app.js';

    if (! is_file($entry)) {
        return [];
    }

    $source = (string) file_get_contents($entry);
    $imported = [];

    foreach (PatternScan::sets('/import\s*\{([^}]*)\}\s*from\s*[\'"]\.\/([A-Za-z0-9_-]+\.js)[\'"]/', $source) as $match) {
        foreach (explode(',', $match[1]) as $name) {
            $name = trim($name);

            if ($name !== '') {
                $imported[$name] = $match[2];
            }
        }
    }

    $factories = [];

    foreach (PatternScan::sets('/\.data\(\s*[\'"`]([A-Za-z0-9_$]+)[\'"`]\s*,\s*([A-Za-z0-9_$]+)\s*\)/', $source) as $match) {
        if (isset($imported[$match[2]])) {
            $factories[$match[1]] = $imported[$match[2]];
        }
    }

    ksort($factories);

    return $factories;
}

/**
 * The same list, plus the two a component arms on itself. A `setTimeout` and a
 * `requestAnimationFrame` are single-shot, so neither is a resource in a start
 * tag — but a factory holds them on `this` and re-arms them, and a retry that
 * re-queues itself is a timer wearing another name.
 *
 * @var list<string>
 */
const FACTORY_RESOURCE_STARTERS = [
    'setInterval(',
    'setTimeout(',
    'requestAnimationFrame(',
    'new EventSource(',
    'new WebSocket(',
    'new MutationObserver(',
    'new ResizeObserver(',
    'new IntersectionObserver(',
    'window.addEventListener(',
    'document.addEventListener(',
];

/**
 * @return array{tags: int, starting: int, offenders: list<string>}
 */
function resourceStartedOffendersIn(string $source): array
{
    $starting = 0;
    $offenders = [];

    // MarkupSource, not a pattern shaped like a tag: `x-data='{ init() {
    // a.map(x => x.y) } }'` carries a `>` inside its own value, and a reader
    // that stops at the first one cuts the attribute holding the answer in half.
    $tags = MarkupSource::tags($source);

    foreach ($tags as $element) {
        // An Alpine element, not any element. A starter in a <script> body
        // belongs to the document, which is torn down by the navigation that
        // removes it.
        if (! $element->hasAttribute('x-data') && ! $element->hasAttribute('x-init')) {
            continue;
        }

        $started = array_values(array_filter(
            RESOURCE_STARTERS,
            static fn (string $starter): bool => str_contains($element->startTag, $starter),
        ));

        if ($started === []) {
            continue;
        }

        $starting++;

        if (str_contains($element->startTag, 'destroy(')) {
            continue;
        }

        $offenders[] = $element->line($source).' — '.implode(', ', $started);
    }

    return ['tags' => count($tags), 'starting' => $starting, 'offenders' => $offenders];
}

it('stops every timer, stream and observer an element started', function (): void {
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    expect(count($views))->toBeGreaterThan(
        200,
        'RepoTree returned '.count($views).' Blade views, which is too few to have read the tree.'
    );

    $tags = 0;
    $starting = 0;
    $offenders = [];

    foreach ($views as $path) {
        $source = (string) file_get_contents($path);
        $read = resourceStartedOffendersIn($source);
        $tags += $read['tags'];
        $starting += $read['starting'];

        foreach ($read['offenders'] as $offender) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).':'.$offender;
        }
    }

    // Read before the verdict: the tag walk is the expensive half, and a reader
    // that stopped early would leave an empty offender list that reads exactly
    // like a clean tree. Measured on this commit: 6,020 opening tags across 282
    // views, three of them starting one of these.
    expect($tags)->toBeGreaterThan(
        3000,
        'the walk found '.$tags.' opening tags, which is too few to be this tree.'
    );

    expect($starting)->toBeGreaterThan(
        0,
        'the walk found no element starting a timer, stream or observer at all.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "This element starts something the DOM does not own, and declares no\n".
        "destroy(). Alpine calls destroy() when the element is removed; nothing\n".
        "else stops a setInterval, an EventSource or an observer. A Livewire\n".
        "morph removes elements all the time — a step flipping, a filter chip, a\n".
        "row leaving a list — and what is left behind still holds \$wire for the\n".
        "component that is no longer on screen. A pairing countdown that outlived\n".
        "its own screen expired a code the confirm step was still using.\n".
        "window and document are not inside the element either: a listener bound\n".
        "to one of them survives the morph, and the event it was bound for may\n".
        "already have fired by the time a later element binds it again.\n".
        "The factory modules under resources/js are held to the same rule by the\n".
        "test below.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

// The other half of the same claim. An Alpine factory is an element-scoped
// component written in JavaScript rather than in an attribute, and it is torn
// down by the same destroy() call — so a timer it holds on `this`, or a listener
// it puts on the window, outlives its element in exactly the same way.
//
// The install hint is why this half exists. It bound beforeinstallprompt from
// its own x-data and took it back off nowhere, which the rule above could not
// see because addEventListener was not on the starter list.
it('stops what an Alpine factory started, in the modules the entry script registers', function (): void {
    $factories = alpineFactoryModules();

    expect(count($factories))->toBeGreaterThan(
        3,
        'Only '.count($factories).' Alpine factories were resolved out of resources/js/app.js, so this rule read almost nothing. '
        .'An import or a registration was rewritten into a shape the resolver does not recognise.'
    );

    $starting = 0;
    $offenders = [];

    foreach (array_unique($factories) as $module) {
        $path = RepoTree::root().'/resources/js/'.$module;

        expect(is_file($path))->toBeTrue('resources/js/'.$module.' is registered with Alpine.data() and is not on disk.');

        $source = (string) file_get_contents($path);

        $started = array_values(array_filter(
            FACTORY_RESOURCE_STARTERS,
            static fn (string $starter): bool => str_contains($source, $starter),
        ));

        if ($started === []) {
            continue;
        }

        $starting++;

        if (str_contains($source, 'destroy(')) {
            continue;
        }

        $offenders[] = 'resources/js/'.$module.' — '.implode(', ', $started);
    }

    expect($starting)->toBeGreaterThan(
        0,
        'No factory module starts a timer, a frame callback, a stream or a listener at all, so this rule proved nothing.'
    );

    sort($offenders);

    expect($offenders)->toBe(
        [],
        "This factory starts something its element does not own and declares no\n".
        "destroy(). Alpine calls destroy() when the element goes and nothing else\n".
        "does: a wire:navigate swap destroys the whole body, and a morph takes a\n".
        "row out of a list mid-gesture. What is left runs against a scope that is\n".
        "already gone: a debounce asking a wire:id the new page does not answer\n".
        "to, a hold timer measuring a detached node, a window listener holding an\n".
        "offer for a component that is no longer there.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
    );
});

it('resolves the factory modules out of the entry script, and not the entry script itself', function (): void {
    $factories = alpineFactoryModules();

    expect($factories)->toHaveKey('emojiActionHold')
        ->and($factories['emojiActionHold'])->toBe('emoji-action-hold.js', 'the registered name is resolved back to the module the factory was imported from');

    expect($factories)->toHaveKey('palette')
        ->and($factories['palette'])->toBe('palette.js');

    // A factory declared in the entry script is out of scope on purpose: that
    // file also carries the page-level machinery — the submit delegate, the
    // theme watcher, the install-offer capture — which binds to the document
    // deliberately and has no element to be stopped with.
    expect(in_array('app.js', array_values($factories), true))->toBeFalse(
        'app.js resolved as a factory module, which would put the page-level machinery under a rule written for elements.'
    );
});

// The tree satisfies the rule, so it reports on what it cannot find and the
// reader is driven against planted sources instead. The near-misses are the
// three shapes that are deliberately allowed: a starter that IS torn down, a
// starter outside Alpine, and an Alpine element that starts nothing.
it('tells an element that stops what it started from one that does not', function (): void {
    $bare = resourceStartedOffendersIn("<p x-data=\"{ t: null }\" x-init=\"t = setInterval(tick, 1000)\">x</p>\n");
    expect($bare['starting'])->toBe(1)
        ->and($bare['offenders'])->toBe(['1 — setInterval(']);

    $stopped = resourceStartedOffendersIn(
        "<p x-data=\"{ t: null, destroy() { clearInterval(this.t) } }\" x-init=\"t = setInterval(tick, 1000)\">x</p>\n"
    );
    expect($stopped['starting'])->toBe(1)
        ->and($stopped['offenders'])->toBe([]);

    // A `>` inside the attribute's own value. A reader that stops at the first
    // one never sees the destroy() that follows it.
    $arrow = resourceStartedOffendersIn(
        "<p x-data='{ t: null, init() { this.t = setInterval(() => this.tick(), 1000) }, destroy() { clearInterval(this.t) } }'>x</p>\n"
    );
    expect($arrow['tags'])->toBe(1)
        ->and($arrow['starting'])->toBe(1)
        ->and($arrow['offenders'])->toBe([]);

    $notAlpine = resourceStartedOffendersIn("<script>setInterval(tick, 1000)</script>\n");
    expect($notAlpine['starting'])->toBe(0)
        ->and($notAlpine['offenders'])->toBe([]);

    $starts_nothing = resourceStartedOffendersIn("<p x-data=\"{ open: false }\">x</p>\n");
    expect($starts_nothing['starting'])->toBe(0)
        ->and($starts_nothing['offenders'])->toBe([]);

    $stream = resourceStartedOffendersIn("<pre x-data=\"{ init() { new EventSource('/s') } }\">x</pre>\n");
    expect($stream['offenders'])->toBe(['1 — new EventSource(']);

    $bound = resourceStartedOffendersIn(
        "<div x-data=\"{ init() { window.addEventListener('beforeinstallprompt', (e) => { this.e = e }) } }\">x</div>\n"
    );
    expect($bound['starting'])->toBe(1)
        ->and($bound['offenders'])->toBe(['1 — window.addEventListener('], 'window outlives the element, so the listener has to come back off it');

    $unbound = resourceStartedOffendersIn(
        "<div x-data=\"{ h: null, init() { this.h = () => 1; document.addEventListener('native-event', this.h) },"
        ." destroy() { document.removeEventListener('native-event', this.h) } }\">x</div>\n"
    );
    expect($unbound['starting'])->toBe(1)
        ->and($unbound['offenders'])->toBe([]);

    $own = resourceStartedOffendersIn(
        "<div x-data=\"{ init() { this.\$el.addEventListener('click', () => 1) } }\">x</div>\n"
    );
    expect($own['starting'])->toBe(0, 'a listener on a node inside the element is collected with the element');
});
