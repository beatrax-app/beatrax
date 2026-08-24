<?php

declare(strict_types=1);

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

/** @return string app.css with every balanced `@layer name { ... }` block removed */
function reflowUnlayeredCss(): string
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    $out = '';
    $offset = 0;
    while (preg_match('/@layer\s+[a-z]+\s*\{/', $css, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $out .= substr($css, $offset, $match[0][1] - $offset);

        $cursor = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        while ($depth > 0 && $cursor < strlen($css)) {
            if ($css[$cursor] === '{') {
                $depth++;
            } elseif ($css[$cursor] === '}') {
                $depth--;
            }
            $cursor++;
        }
        $offset = $cursor;
    }

    return $out.substr($css, $offset);
}

function reflowRule(): string
{
    $css = reflowUnlayeredCss();

    $start = strpos($css, 'h1,'."\n".'    h2,'."\n".'    h3,');

    expect($start)->not->toBeFalse('No unlayered reflow rule; a layered one loses to the utilities beside it.');

    $end = strpos($css, '}', (int) $start);

    return substr($css, (int) $start, (int) $end - (int) $start);
}

it('lets an unbreakable run break rather than take the page sideways', function (): void {
    expect(reflowRule())->toContain('overflow-wrap: anywhere');
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
    expect(reflowRule())->not->toContain('break-word');
});
