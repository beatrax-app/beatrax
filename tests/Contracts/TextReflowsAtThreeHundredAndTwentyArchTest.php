<?php

declare(strict_types=1);

use Tests\Contracts\Support\UnlayeredCss;

// Measured on an SM-S928B at the largest font size (2.0) and largest screen
// zoom (720dpi) its own sliders offer: a 320px viewport with 32px text. Eleven
// routes scrolled sideways, every one of them on a single run of characters
// with no break opportunity in it —
//
//   /drift/watch            402px  "Subscription drift", and the "Drift
//                                   alerts →" link pushed fully off-screen
//   /uncategorized          387px  the page heading
//   /community/mystery-m…   382px  APPS COMMERCE:redacted-domain.example:…
//   /community              370px  Modules/*/Resources/lang in a paragraph
//   /settings               446px  the privacy-policy URL, printed in mono
//   /counterparties         343px  "counterparties"
//
// `overflow-wrap: break-word` was tried first and moved none of them except
// /counterparties: it breaks a word that overflows its line box but is ignored
// when the browser computes min-content, so the flex and grid parents kept
// sizing themselves to the unbroken run. `anywhere` counts, which is what
// lets the parent shrink.

function reflowRule(): string
{
    // Read through UnlayeredCss rather than by hand: its ruleAt() answers null
    // when the block never closes, where a `strpos(…, '}')` of `false` cast to
    // int made the rule the empty string and every assertion below it a
    // question about nothing.
    $rule = UnlayeredCss::ruleAt('h1,'."\n".'    h2,'."\n".'    h3,');

    expect($rule)->not->toBeNull('No unlayered reflow rule; a layered one loses to the utilities beside it.');

    return (string) $rule;
}

it('lets an unbreakable run break rather than take the page sideways', function (): void {
    expect(str_contains(reflowRule(), 'overflow-wrap: anywhere'))->toBeTrue(
        'The reflow rule no longer says `anywhere`, so a flex or grid parent goes back to '
        .'sizing itself to an unbreakable run and eleven routes scroll sideways at 320px.',
    );
});

it('covers the prose and the machine strings, not only the headings', function (): void {
    $rule = reflowRule();

    $missing = [];

    // p and code carried /community and /community/mystery-merchants; the
    // headings alone left both of them over 320.
    foreach (['h1', 'h2', 'h3', 'h4', 'p', 'li', 'dd', 'dt', 'code'] as $element) {
        if (preg_match('/(?:^|\s)'.preg_quote($element, '/').'\s*[,{]/m', $rule) !== 1) {
            $missing[] = $element;
        }
    }

    expect($missing)->toBe([], 'The reflow rule does not reach: '.implode(', ', $missing));
});

// break-word is the near-identical value that does not work here, and it is
// what a later edit would reach for.
it('does not settle for break-word, which measured no change on five of six', function (): void {
    expect(str_contains(reflowRule(), 'break-word'))->toBeFalse(
        'break-word breaks a word that overflows its line box and is ignored when the browser '
        .'computes min-content, so the flex and grid parents keep sizing themselves to the '
        .'unbroken run. Only `anywhere` counts there.',
    );
});
