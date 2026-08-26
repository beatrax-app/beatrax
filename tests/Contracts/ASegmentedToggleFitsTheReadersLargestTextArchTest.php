<?php

declare(strict_types=1);

// Measured on an SM-S928B at the two largest settings its own sliders offer —
// font size 2.0 and screen zoom 720dpi, which is a 320px viewport with 32px
// text. The currency toggle on /transactions is one non-wrapping row of two
// nowrap labels: it measured 372.7px, "Original currency" ended 80px past the
// right edge, and it was the widest thing on the page, so the whole page
// scrolled sideways behind it.
//
// Wrapping alone is not the fix — the group carries `h-10`, so the second row
// drew underneath the "Show full history" button below it. The height has to
// be released with it, and the rule has to be unlayered or `h-10` wins.
//
// The same control is also 32px tall at default text: the coarse-pointer floor
// lists buttons, and a radio is not one.

/** @return string app.css with every balanced `@layer name { ... }` block removed */
function segmentedToggleUnlayeredCss(): string
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

function segmentedToggleRule(string $selector): string
{
    $css = segmentedToggleUnlayeredCss();

    $start = strpos($css, $selector.' {');

    expect($start)->not->toBeFalse("No unlayered rule for {$selector}; a layered one loses to Flux's own utilities.");

    $end = strpos($css, '}', (int) $start);

    return substr($css, (int) $start, (int) $end - (int) $start);
}

it('lets the segmented toggle wrap instead of running off the right edge', function (): void {
    $rule = segmentedToggleRule('[data-flux-radio-group-segmented]');

    $missing = [];

    foreach (['flex-wrap: wrap', 'height: auto'] as $property) {
        if (! str_contains($rule, $property)) {
            $missing[] = $property;
        }
    }

    expect($missing)->toBe([], 'The wrap is incomplete, missing: '.implode(', ', $missing));
});

it('puts the segmented options on the touch floor the surrounding buttons stand on', function (): void {
    expect(segmentedToggleRule("[data-flux-radio-group-segmented] > [role='radio']"))
        ->toContain('min-height: 44px');
});

// Flux emits the marker attribute, not a class we own, so a Flux upgrade that
// renamed it would leave both rules selecting nothing at all.
it('still has a control the rules can select', function (): void {
    $views = [];
    foreach (['Modules', 'resources'] as $root) {
        $directory = new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $views[] = (string) $file;
            }
        }
    }

    $segmented = array_values(array_filter(
        $views,
        static fn (string $view): bool => str_contains((string) file_get_contents($view), 'variant="segmented"'),
    ));

    expect($segmented)->not->toBe([], 'Nothing renders a segmented group any more; the rules guard nothing.');
});
