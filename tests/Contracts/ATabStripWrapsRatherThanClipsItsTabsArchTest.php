<?php

declare(strict_types=1);

// The three routes still over 320px once long runs could break were all the
// same shape: a row of controls whose labels do not shrink, in a container
// with no wrap.
//
// Measured on an SM-S928B at font size 2.0 and screen zoom 720dpi:
//
//   /drift     the Open / History / Dismissed strip needed 306px of a 288px
//              column, and each tab was clipped inside its own box —
//              "Dismissed" was given 116px of the 133px it asked for
//   /settings  Light / Dark / System, 271px of 256, inside an
//              overflow-hidden pill
//   /calendar  the Accounts ▾ filter, 6px past the right edge
//
// role=tablist and role=radiogroup are what the strips already have in common
// across DriftAlerts, Notifications, Counterparties, Forecasting and DevMode,
// so the rule follows the role rather than a class each one would have to
// remember to add.

/** @return string app.css with every balanced `@layer name { ... }` block removed */
function tabStripUnlayeredCss(): string
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

it('wraps a strip of tabs instead of clipping the last one', function (): void {
    $css = tabStripUnlayeredCss();

    $start = strpos($css, "[role='tablist'],");

    expect($start)->not->toBeFalse('No unlayered wrap rule for a tab strip.');

    $rule = substr($css, (int) $start, (int) strpos($css, '}', (int) $start) - (int) $start);

    $missing = [];

    foreach (["[role='radiogroup']", '.cal-toolbar', 'flex-wrap: wrap'] as $part) {
        if (! str_contains($rule, $part)) {
            $missing[] = $part;
        }
    }

    expect($missing)->toBe([], 'The wrap rule does not reach: '.implode(', ', $missing));
});

// A rule keyed on a role guards nothing if the strips stop carrying it.
it('still has strips that answer to the role it selects', function (): void {
    $views = [];
    foreach (['Modules', 'resources'] as $root) {
        $directory = new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS);
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if (str_ends_with((string) $file, '.blade.php')) {
                $views[] = (string) $file;
            }
        }
    }

    $tablists = 0;
    $radiogroups = 0;
    foreach ($views as $view) {
        $source = (string) file_get_contents($view);
        $tablists += substr_count($source, 'role="tablist"');
        $radiogroups += substr_count($source, 'role="radiogroup"');
    }

    expect($tablists)->toBeGreaterThan(0)
        ->and($radiogroups)->toBeGreaterThan(0);
});
