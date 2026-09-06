<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// Fourteen rules asked for `var(--color-muted, oklch(60% 0 0))` and nine for
// `var(--color-accent, …)`. Neither token has ever existed: the real names are
// --color-text-muted and --color-blue. Every one of those rules silently took
// its fallback, so they rendered the same unthemed grey and blue in light and
// dark alike, and the /reports filter labels sat at 3.77:1 because of it.
// A fallback on a name that is never defined does not soften a failure -- it
// hides one, and it hides it in the one place nobody reads.

/**
 * The `--color-…` tokens one source declares, then every one it reaches for.
 * Named and taking a source string so the control below drives the same reader
 * the walk drives.
 *
 * @return array{0: list<string>, 1: list<string>}
 */
function colourTokensIn(string $source): array
{
    $defined = PatternScan::all('/^\s*(--color-[a-z0-9-]+)\s*:/mi', $source);
    $referenced = PatternScan::all('/var\(\s*(--color-[a-z0-9-]+)/i', $source);

    return [array_values(array_unique($defined[1])), array_values(array_unique($referenced[1]))];
}

/** @return array{0: list<string>, 1: list<string>} the tokens defined, then every one referenced */
function colourTokensInStylesheet(): array
{
    return colourTokensIn((string) file_get_contents(base_path('resources/css/app.css')));
}

it('does not reach for a colour token that was never declared', function (): void {
    [$defined, $referenced] = colourTokensInStylesheet();

    // 22 tokens stand in the stylesheet today, and every one of them is used.
    // A pattern that read none of them would call every reference below
    // undefined, or — reading neither half — report a clean sheet.
    expect(count($defined))->toBeGreaterThan(10, 'No colour tokens were found at all, so the pattern this rule '
        .'reads has changed and the rule is no longer measuring anything.');
    expect(count($referenced))->toBeGreaterThan(10, 'No colour token is referenced at all, so this rule compared nothing.');

    $missing = array_values(array_diff($referenced, $defined));
    sort($missing);

    expect($missing)->toBe(
        [],
        'These colour tokens are used but never defined, so every rule using one silently takes its '
        ."fallback and stops following the theme:\n  ".implode("\n  ", $missing)
    );
});

// The stylesheet is not the only place a token is reached for: a template can
// write `style="color: var(--color-x)"` inline, and a name misspelt there is
// the same silent fallback in the one place no stylesheet reader looks.
it('does not reach for an undeclared colour token from a template either', function (): void {
    [$defined] = colourTokensInStylesheet();
    $views = RepoTree::files(RepoTree::EVERY_BLADE_VIEW);

    expect(count($views))->toBeGreaterThan(
        100,
        'The Blade walk opened almost nothing, so no inline colour reference was read at all.'
    );

    $offenders = [];
    $references = 0;

    foreach ($views as $path) {
        [, $referenced] = colourTokensIn((string) file_get_contents($path));
        $references += count($referenced);

        foreach (array_diff($referenced, $defined) as $token) {
            $offenders[] = str_replace(RepoTree::root().'/', '', $path).' — '.$token;
        }
    }

    sort($offenders);

    expect($references)->toBeGreaterThan(
        20,
        'No template reaches for a colour token at all, so this rule read nothing.'
    );

    expect($offenders)->toBe([], implode("\n  ", [
        'These templates reach for a colour token the stylesheet never declares:',
        ...$offenders,
        '',
        'The declaration silently takes its fallback, so the colour stops following the',
        'theme and looks correct in whichever mode the fallback was written for.',
    ]));
});

// The reader is where both rules get their subject, and one that matched
// nothing would call every stylesheet clean.
it('reads a declaration and a reference apart', function (): void {
    $css = <<<'CSS'
        :root {
            --color-text-muted: oklch(60% 0 0);
        }
        .label { color: var(--color-text-muted); }
        .ghost { color: var(--color-muted, oklch(60% 0 0)); }
        CSS;

    [$defined, $referenced] = colourTokensIn($css);

    expect($defined)->toBe(['--color-text-muted']);
    expect($referenced)->toBe(['--color-text-muted', '--color-muted']);
    expect(array_values(array_diff($referenced, $defined)))->toBe(['--color-muted']);
});
