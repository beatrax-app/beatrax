<?php

declare(strict_types=1);

use Modules\Core\Public\Exceptions\MarkupParseFailedException;
use Modules\Core\Public\Support\MarkupSource;
use Modules\Core\Public\Support\PatternScan;
use Modules\Core\Public\Support\RenderedMarkup;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-guard-that-reads-html-with-a-regex
 */

// A guard hunting offenders is the one place a wrong answer is invisible, and a
// tag-shaped pattern gives wrong answers in both directions: `[^>]*` ends the
// tag at the first `>` an Alpine expression or a `@class([… => …])` puts inside
// an attribute, and `.*?</tag>` closes it at the first nested one.

// Each entry was read and is a question about a string rather than about an
// element, so a tree would answer nothing a pattern does not.
const MARKUP_PATTERNS_KEPT = [
    // A window of characters after the row, deliberately: the comment above it
    // says so, and the heading it looks for is a sibling further down the file
    // rather than a descendant of anything this guard has hold of.
    'AHeadingIsNeverSqueezedByItsOwnActionArchTest.php' => ['/<h[12][\s>]/'],
    // The alias a view mounts, which is a name and not an element: no attribute
    // is read, and the mount may be spelled with or without a closing tag.
    'BoundaryArchTest.php' => ['/<livewire:([A-Za-z0-9._-]+)/'],
    // Comment extraction, not a markup query. It is looking for the regions of
    // a Blade file that hold PHP or JS so it can read the comments in them.
    'CommentPolicyArchTest.php' => ['/@php\b(?<body>.*?)@endphp|<\?php(?<php>.*?)\?>|<script\b[^>]*>(?<js>.*?)<\/script>/si'],
];

/**
 * @return list<array{file: string, line: int, pattern: string}>
 */
function markupShapedPatternsIn(string $source, string $file): array
{
    $found = [];

    foreach (token_get_all($source) as $token) {
        if (! is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $body = substr($token[1], 1, -1);

        if (! markupShapedPattern($body)) {
            continue;
        }

        $found[] = ['file' => $file, 'line' => $token[2], 'pattern' => $body];
    }

    return $found;
}

// A named group opens with the same two characters a closing tag does, so it is
// neutralised before the shape is measured rather than counted as one.
function markupShapedPattern(string $literal): bool
{
    if (strlen($literal) < 3 || ! in_array($literal[0], ['/', '~', '#', '%'], true)) {
        return false;
    }

    return PatternScan::matches('~<\\\\?/?\[?[a-zA-Z]~', str_replace('(?<', '(?:', $literal));
}

it('reads markup through the seam, or names the pattern it keeps and why', function (): void {
    $unexplained = [];

    foreach ((array) glob(dirname(__DIR__).'/Contracts/*.php') as $path) {
        $file = basename((string) $path);

        if ($file === basename(__FILE__)) {
            continue;
        }

        foreach (markupShapedPatternsIn((string) file_get_contents((string) $path), $file) as $site) {
            if (in_array($site['pattern'], MARKUP_PATTERNS_KEPT[$file] ?? [], true)) {
                continue;
            }

            $unexplained[] = $site['file'].':'.$site['line'].'  '.$site['pattern'];
        }
    }

    expect($unexplained)->toBe([], implode("\n", [
        'These guards read markup with a pattern shaped like a tag. Use',
        'MarkupSource for template source and RenderedMarkup for a response body,',
        'or add the pattern to MARKUP_PATTERNS_KEPT with the reason it is not a',
        'structural question. That list may shrink; it may not grow silently.',
        '',
        ...$unexplained,
    ]));
});

it('goes red on a planted pattern and stays quiet on one that only looks like markup', function (): void {
    $planted = <<<'PHP'
        <?php
        $a = preg_match('/<button[^>]*disabled/', $html);
        $b = preg_match('/^[a-z]+$/', $word);
        $c = '/(?<name>[A-Za-z]+)/';
        $d = 'a plain string with <b> in it';
        PHP;

    $found = markupShapedPatternsIn($planted, 'planted.php');

    expect(array_column($found, 'pattern'))->toBe(['/<button[^>]*disabled/']);
});

it('raises rather than answering nothing found when the source cannot be read', function (): void {
    expect(fn (): array => MarkupSource::elements('<button aria-label="Save"', 'button'))
        ->toThrow(MarkupParseFailedException::class);

    expect(fn (): RenderedMarkup => RenderedMarkup::of(''))
        ->toThrow(MarkupParseFailedException::class);

    expect(fn (): RenderedMarkup => RenderedMarkup::of('<div>x</div>')->firstOrFail('#absent'))
        ->toThrow(MarkupParseFailedException::class);
});
