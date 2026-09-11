<?php

declare(strict_types=1);

use Tests\Contracts\Support\PcreCallSites;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a-needle-short-enough-to-match-generated-markup
 */

// `assertSee` and `assertDontSee` are substring matches over the whole
// response, and a Livewire render carries three digit runs nobody wrote:
// `wire:key="lw-<crc32 of the view's path>-<n>"`, a twenty-character
// `wire:id`, and a sixty-four-character snapshot checksum.

const MARKUP_NEEDLE_METHODS = [
    'assertSee',
    'assertDontSee',
    'assertSeeHtml',
    'assertDontSeeHtml',
    'assertSeeInOrder',
    'assertSeeHtmlInOrder',
];

// The Text variants are absent on purpose: both strip the tags first, so no
// attribute answers them.

/** @return list<string> every test file in this repository */
function markupNeedleTestFiles(): array
{
    return array_values(array_filter(
        RepoTree::files(RepoTree::EVERY_PHP_FILE),
        static fn (string $path): bool => str_contains($path, '/tests/') && str_ends_with($path, 'Test.php'),
    ));
}

function isADigitRun(string $needle): bool
{
    $digits = str_starts_with($needle, '-') ? substr($needle, 1) : $needle;

    return $digits !== '' && strlen($digits) <= 10 && ctype_digit($digits);
}

// A literal one bracket deep inside an array argument is still a needle;
// anything deeper is an argument to something else — the count in
// `Lang::choice('k', 3, ['count' => '3'])` is not what the assertion reads.
/**
 * @param  list<array{id: int|null, text: string, line: int}>  $tokens
 * @return list<string>
 */
function markupNeedlesFrom(array $tokens, int $open): array
{
    $needles = [];
    $nesting = [];

    for ($i = $open + 1, $total = count($tokens); $i < $total; $i++) {
        $text = $tokens[$i]['text'];

        if (in_array($text, ['(', '[', '{'], true) || in_array($tokens[$i]['id'], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) {
            $nesting[] = $text === '[' ? '[' : '(';

            continue;
        }

        if (in_array($text, [')', ']', '}'], true)) {
            if ($nesting === []) {
                return $needles;
            }

            array_pop($nesting);

            continue;
        }

        if ($tokens[$i]['id'] === T_CONSTANT_ENCAPSED_STRING && $nesting === array_fill(0, count($nesting), '[')) {
            $needles[] = markupNeedleValue($text);
        }
    }

    return $needles;
}

function markupNeedleValue(string $literal): string
{
    $inner = substr($literal, 1, -1);

    return $literal[0] === "'"
        ? str_replace(["\\'", '\\\\'], ["'", '\\'], $inner)
        : $inner;
}

/**
 * @return array{sites: int, offenders: list<string>}
 */
function markupNeedleReading(string $source, string $file): array
{
    $tokens = PcreCallSites::significantTokens($source);
    $sites = 0;
    $offenders = [];

    foreach ($tokens as $index => $token) {
        if ($token['id'] !== T_STRING || ! in_array($token['text'], MARKUP_NEEDLE_METHODS, true)) {
            continue;
        }

        if (! in_array($tokens[$index - 1]['text'] ?? '', ['->', '?->'], true) || ($tokens[$index + 1]['text'] ?? '') !== '(') {
            continue;
        }

        $sites++;

        foreach (markupNeedlesFrom($tokens, $index + 1) as $needle) {
            if (isADigitRun($needle)) {
                $offenders[] = $file.':'.$token['line'].'  '.$token['text'].'(\''.$needle.'\')';
            }
        }
    }

    return ['sites' => $sites, 'offenders' => $offenders];
}

it('asks a digit run of the reader, never of the response it was rendered into', function (): void {
    $files = markupNeedleTestFiles();
    $offenders = [];
    $sites = 0;

    foreach ($files as $path) {
        $reading = markupNeedleReading((string) file_get_contents($path), str_replace(RepoTree::root().'/', '', $path));

        $sites += $reading['sites'];
        $offenders = [...$offenders, ...$reading['offenders']];
    }

    // Both floors sit far under what the tree holds. A tokeniser that stopped
    // reading finds no offender either, which is the answer a clean tree gives.
    expect(count($files))->toBeGreaterThan(
        1_500,
        'The walk opened '.count($files).' test files, which is too few to have read the suite at all.',
    );
    expect($sites)->toBeGreaterThan(
        800,
        'The tokeniser found '.$sites.' assertions of this family, so it stopped rather than found them clean.',
    );

    expect($offenders)->toBe([], implode("\n", [
        'These assertions hand a bare digit run to a substring match over rendered',
        'markup. Livewire folds a crc32 of the view\'s own path into wire:key, so the',
        'same needle passes on one checkout and goes red on the next. Read the text',
        'through Modules\\Core\\Public\\Support\\RenderedMarkup::of($html)->text() and',
        'assert against what the reader is shown.',
        '',
        ...$offenders,
    ]));
});

it('reads the needle the assertion takes and not the arguments underneath it', function (): void {
    $planted = <<<'PHP'
        <?php
        $page->assertDontSee('-1250');
        $page->assertSee(Lang::choice('k', 3, ['count' => '3']));
        $page->assertDontSee(['1970', 'never synced']);
        $page->assertSeeText('2');
        $page->assertSee('€750');
        $page->assertSee("{$prefix}9");
        PHP;

    $reading = markupNeedleReading($planted, 'planted.php');

    expect($reading['sites'])->toBe(5)
        ->and($reading['offenders'])->toBe([
            'planted.php:2  assertDontSee(\'-1250\')',
            'planted.php:4  assertDontSee(\'1970\')',
        ]);
});
