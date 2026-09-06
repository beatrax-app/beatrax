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
//
// Keyed by repo-relative path and not by basename: a basename excuses a file of
// that name in any of the four directories this now walks, which is a wider
// exemption than any of these three was granted.
const MARKUP_PATTERNS_KEPT = [
    // A window of characters after the row, deliberately: the comment above it
    // says so, and the heading it looks for is a sibling further down the file
    // rather than a descendant of anything this guard has hold of.
    'tests/Contracts/AHeadingIsNeverSqueezedByItsOwnActionArchTest.php' => ['/<h[12][\s>]/'],
    // The alias a view mounts, which is a name and not an element: no attribute
    // is read, and the mount may be spelled with or without a closing tag.
    'tests/Contracts/BoundaryArchTest.php' => ['/<livewire:([A-Za-z0-9._-]+)/'],
    // Comment extraction, not a markup query. It is looking for the regions of
    // a Blade file that hold PHP or JS so it can read the comments in them.
    'tests/Contracts/CommentPolicyArchTest.php' => ['/@php\b(?<body>.*?)@endphp|<\?php(?<php>.*?)\?>|<script\b[^>]*>(?<js>.*?)<\/script>/si'],
];

// The rule is about guards, and a guard is not only a file under
// tests/Contracts: the shared scanners sit a directory down, thirteen more live
// beside the module they read, and tests/Helpers is where two CSS guards get
// their reading. All four were outside the walk, so a tag-shaped pattern in any
// of them was excused by nobody having looked.
/** @return list<string> every file a guard in this repository is written in */
function markupGuardFiles(): array
{
    $paths = [];

    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('tests/Contracts'), FilesystemIterator::SKIP_DOTS),
    );

    /** @var SplFileInfo $file */
    foreach ($walk as $file) {
        if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
            $paths[] = $file->getPathname();
        }
    }

    foreach (['Modules/*/tests/Arch/*.php', 'Modules/*/tests/Contracts/*.php', 'tests/Helpers/*.php'] as $pattern) {
        foreach ((array) glob(base_path($pattern)) as $path) {
            $paths[] = (string) $path;
        }
    }

    sort($paths);

    return $paths;
}

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
    $kept = [];
    $walked = 0;
    $literals = 0;

    $self = str_replace(base_path().'/', '', __FILE__);

    foreach (markupGuardFiles() as $path) {
        $file = str_replace(base_path().'/', '', $path);

        // This file declares every kept pattern as a literal of its own, so a
        // reader that opened it would report the list as a list of offences.
        if ($file === $self) {
            continue;
        }

        $walked++;
        $source = (string) file_get_contents($path);
        $literals += count(array_filter(
            token_get_all($source),
            static fn (array|string $token): bool => is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING,
        ));

        foreach (markupShapedPatternsIn($source, $file) as $site) {
            if (in_array($site['pattern'], MARKUP_PATTERNS_KEPT[$file] ?? [], true)) {
                $kept[$file][] = $site['pattern'];

                continue;
            }

            $unexplained[] = $site['file'].':'.$site['line'].'  '.$site['pattern'];
        }
    }

    // Both floors are far under what the four directories hold — 295 guards and
    // twenty-one thousand string literals. A tokeniser that stopped reading
    // finds no tag-shaped pattern, which is the answer a compliant tree gives.
    expect($walked)->toBeGreaterThan(
        150,
        'The walk opened '.$walked.' guards, which is too few to have read the guard tree at all.',
    );
    expect($literals)->toBeGreaterThan(
        8000,
        'The tokeniser read '.$literals.' string literals out of those guards, so it stopped rather than found them clean.',
    );

    expect($unexplained)->toBe([], implode("\n", [
        'These guards read markup with a pattern shaped like a tag. Use',
        'MarkupSource for template source and RenderedMarkup for a response body,',
        'or add the pattern to MARKUP_PATTERNS_KEPT with the reason it is not a',
        'structural question. That list may shrink; it may not grow silently.',
        '',
        ...$unexplained,
    ]));

    // A kept pattern nobody writes any more excuses nothing, and it reads as an
    // exception somebody argued for. The list may shrink; it may not rot.
    $reached = array_map(static function (array $patterns): array {
        $found = array_values(array_unique($patterns));
        sort($found);

        return $found;
    }, $kept);
    ksort($reached);

    $declared = array_map(static function (array $patterns): array {
        $listed = $patterns;
        sort($listed);

        return $listed;
    }, MARKUP_PATTERNS_KEPT);
    ksort($declared);

    expect($reached)->toBe($declared, implode("\n", [
        'A pattern kept in MARKUP_PATTERNS_KEPT is no longer written in the file it',
        'names, so the entry excuses nothing and the next reader will take it for a',
        'decision. Delete it, or point it at the pattern that replaced it.',
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
