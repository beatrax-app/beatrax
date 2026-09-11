<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;
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
        "This rule reads Blade only. A factory in resources/js registered with\n".
        "Alpine.data() is not walked, so the same shape there is on the author.\n".
        "Offenders:\n  ".implode("\n  ", $offenders),
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
});
