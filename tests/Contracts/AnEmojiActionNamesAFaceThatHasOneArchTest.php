<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Helpers\CssRule;

// The one rule in the app that asks for emoji presentation named no font, so it
// asked the body stack, which is system faces and has no emoji in it. Core Text
// reaches Apple Color Emoji by itself; an Android WebView has no such face to
// fall through to.

const EMOJI_MARK_SELECTOR = '.emoji-action__mark';

// One face per platform, because no single family is installed on all three.
const EMOJI_FACES = ['Apple Color Emoji', 'Segoe UI Emoji', 'Noto Color Emoji'];

beforeEach(function (): void {
    $this->css = (string) file_get_contents(base_path('resources/css/app.css'));
});

it('names a colour emoji face for every platform on the mark that asks for one', function (): void {
    $rule = CssRule::blockFor($this->css, EMOJI_MARK_SELECTOR);

    expect($rule)->not->toBe('', 'No rule declares '.EMOJI_MARK_SELECTOR.'.')
        ->and($rule)->toContain('font-family: var(--font-emoji);');

    $declaration = PatternScan::first('/--font-emoji:([^;]+);/', $this->css);

    expect($declaration)->not->toBeEmpty('The --font-emoji token is gone, so the mark names a family nothing defines.');

    $missing = array_values(array_filter(
        EMOJI_FACES,
        static fn (string $face): bool => ! str_contains($declaration[1], $face),
    ));

    expect($missing)->toBe([], 'A platform whose face is unnamed draws nothing at all.');
});

// The stylesheet is one file for both shells, so a second rule asking for the
// presentation without naming a face would reintroduce the same asymmetry
// somewhere else on the page.
it('leaves no rule asking for emoji presentation from a stack that has no emoji in it', function (): void {
    $css = (string) preg_replace('~/\*.*?\*/~s', '', $this->css);

    $matches = PatternScan::sets('/([^{}]+)\{([^{}]*font-variant-emoji\s*:\s*emoji[^{}]*)\}/', $css);

    expect($matches)->not->toBeEmpty('No rule asks for emoji presentation at all — the mark has lost its own rule.');

    $unnamed = [];

    foreach ($matches as $match) {
        if (! str_contains($match[2], 'font-family')) {
            $unnamed[] = trim($match[1]);
        }
    }

    expect($unnamed)->toBe([]);
});
