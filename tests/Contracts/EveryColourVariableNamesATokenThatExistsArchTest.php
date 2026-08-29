<?php

declare(strict_types=1);

// Fourteen rules asked for `var(--color-muted, oklch(60% 0 0))` and nine for
// `var(--color-accent, …)`. Neither token has ever existed: the real names are
// --color-text-muted and --color-blue. Every one of those rules silently took
// its fallback, so they rendered the same unthemed grey and blue in light and
// dark alike, and the /reports filter labels sat at 3.77:1 because of it.
// A fallback on a name that is never defined does not soften a failure -- it
// hides one, and it hides it in the one place nobody reads.

/**
 * @return array{0: list<string>, 1: list<string>} the tokens defined, then every one referenced
 */
function colourTokensInStylesheet(): array
{
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    preg_match_all('/^\s*(--color-[a-z0-9-]+)\s*:/mi', $css, $defined);
    preg_match_all('/var\(\s*(--color-[a-z0-9-]+)/i', $css, $referenced);

    return [array_values(array_unique($defined[1])), array_values(array_unique($referenced[1]))];
}

it('does not reach for a colour token that was never declared', function (): void {
    [$defined, $referenced] = colourTokensInStylesheet();

    expect($defined)->not->toBeEmpty('No colour tokens were found at all, so the pattern this rule '
        .'reads has changed and the rule is no longer measuring anything.');

    $missing = array_values(array_diff($referenced, $defined));
    sort($missing);

    expect($missing)->toBe(
        [],
        'These colour tokens are used but never defined, so every rule using one silently takes its '
        ."fallback and stops following the theme:\n  ".implode("\n  ", $missing)
    );
});
