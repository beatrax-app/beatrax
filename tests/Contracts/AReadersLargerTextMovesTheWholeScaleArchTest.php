<?php

declare(strict_types=1);

use Tests\Helpers\CssRule;

// One rule carries Dynamic Type for the whole product, and it is easy to break
// three different ways while looking correct.
//
// Dropping the family and the line-height restatements is the quiet one: `font`
// is a shorthand, so it replaces the app's own stack and leading with Apple's
// on every iPhone at once. Unscoping it from coarse
// pointers is the loud one -- -apple-system-body is 13px on macOS, so desktop
// Safari would shrink to 81% to fix a phone. Deleting it takes the reader's
// text-size choice away entirely, which is the state it was written to end.

function dynamicTypeStylesheet(): string
{
    $path = base_path('resources/css/app.css');

    expect($path)->toBeReadableFile();

    return (string) file_get_contents($path);
}

it('moves the root from the size the reader set', function (): void {
    $block = CssRule::blockFor(dynamicTypeStylesheet(), '@supports (font: -apple-system-body)');

    expect($block)->toContain(':root')
        // The one channel WebKit gives a page to the reader's Dynamic Type
        // setting. Every rem in the product hangs off it.
        ->and($block)->toContain('font: -apple-system-body;');
});

it('puts back what the shorthand took', function (): void {
    $block = CssRule::blockFor(dynamicTypeStylesheet(), '@supports (font: -apple-system-body)');

    expect($block)->toContain('font-family: var(--font-sans);')
        ->and($block)->toContain('line-height: 1.5;');
});

// macOS resolves the same keyword to 13px, and the desktop app and desktop
// Safari both read this stylesheet.
it('reaches no pointer but a finger', function (): void {
    $enclosing = CssRule::atRuleEnclosing(
        dynamicTypeStylesheet(),
        '@supports (font: -apple-system-body)',
    );

    expect($enclosing)->toBe('@media (pointer: coarse)');
});

// The rule moves the root and nothing else; it reaches the type scale only
// because the scale is expressed in rem. A token rewritten in px would leave
// the reader's choice moving everything around the words it was set for.
it('leaves the type scale in the unit the root can move', function (): void {
    $css = dynamicTypeStylesheet();

    // Matched on the definition rather than inside a :root block: the
    // stylesheet opens several of them and only one carries the scale.
    foreach (['--text-xs', '--text-sm', '--text-base', '--text-md', '--text-lg', '--text-xl'] as $token) {
        expect($css)->toMatch('/'.preg_quote($token, '/').':\s*[0-9.]+rem;/');
    }
});
