<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// Reflow at 320px gives every heading `overflow-wrap: anywhere` so a word with
// no break opportunity cannot take the page sideways. That is the right trade
// and it stays; what it leaves is the break itself. Dutch titles /drift
// "Afwijkingswaarschuwingen" -- 353px of word in a 343px column -- and it split
// after "waarschuwinge", leaving an orphan "n" on its own line at 30px.
/** @return array{0: string, 1: string} the reflow selector, then the heading-only one */
function headingWrapSelectors(): array
{
    return [
        "h1,\n    h2,\n    h3,\n    h4,\n    h5,\n    h6,\n    p,",
        "h1,\n    h2,\n    h3,\n    h4,\n    h5,\n    h6 {",
    ];
}

it('lets a heading hyphenate before it falls back to breaking anywhere', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    [$reflowSelector, $headingSelector] = headingWrapSelectors();
    // The reflow rule is what stops a long word taking the page sideways;
    // hyphenation only chooses a better break within it.
    $reflow = CssRule::blockFor($css, $reflowSelector);
    expect($reflow)->toContain('overflow-wrap: anywhere;');

    $headings = CssRule::blockFor($css, $headingSelector);
    expect($headings)->not->toBe('', 'The heading-only hyphenation rule is gone, so a heading falls back '
        .'to breaking at whatever character ran out of room.')
        ->and($headings)->toContain('hyphens: auto;')
        ->and($headings)->toContain('-webkit-hyphens: auto;')
        ->and($headings)->toContain('text-wrap: balance;');
});

// A hyphen inserted into a rendered value changes what the value says, so the
// rule that carries `code` may not be the one that carries hyphenation.
it('does not hyphenate anything that prints a value', function (): void {
    $css = (string) file_get_contents(base_path('resources/css/app.css'));

    [$reflowSelector, $headingSelector] = headingWrapSelectors();
    $reflow = CssRule::blockFor($css, $reflowSelector);

    expect($reflow)->not->toContain('hyphens');

    $reflowAt = strpos($css, $reflowSelector);
    $headingAt = strpos($css, $headingSelector);

    expect($reflowAt)->not->toBeFalse()
        ->and($headingAt)->not->toBeFalse()
        ->and($headingAt)->toBeGreaterThan($reflowAt, 'The hyphenation rule has to come after the reflow '
            .'rule it narrows, or the cascade drops it.');
});
