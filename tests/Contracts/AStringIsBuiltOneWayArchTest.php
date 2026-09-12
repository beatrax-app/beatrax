<?php

declare(strict_types=1);

use Modules\Core\Public\Support\BladePhpSource;
use Tests\Contracts\Support\RepoTree;

/**
 * @link ../../.docs/conventions/one-way-to-build-a-string.md
 */

// Below this the walk read almost nothing and a clean answer means the scope
// moved, not that the tree is clean.
const INTERPOLATION_FILE_FLOOR = 5000;

// Heredoc and nowdoc are exempt and stay readable as they are: a block of text
// with values in it is the one place interpolation beats a format string, and
// the tokenizer brackets those bodies so they are skipped rather than matched.
const INTERPOLATION_TOKENS = [T_VARIABLE, T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES];

/** @return list<int> the line of each interpolated double-quoted string */
function interpolatedStringLines(string $source): array
{
    $tokens = @token_get_all($source);

    if (! is_array($tokens)) {
        return [];
    }

    $lines = [];
    $inHeredoc = false;
    $open = false;
    $line = null;
    $found = false;

    foreach ($tokens as $token) {
        if (is_array($token) && $token[0] === T_START_HEREDOC) {
            $inHeredoc = true;

            continue;
        }

        if (is_array($token) && $token[0] === T_END_HEREDOC) {
            $inHeredoc = false;

            continue;
        }

        if ($inHeredoc) {
            continue;
        }

        if ($token === '"') {
            if ($open && $found && $line !== null) {
                $lines[] = $line;
            }

            $open = ! $open;
            $found = false;
            $line = null;

            continue;
        }

        if ($open && is_array($token) && in_array($token[0], INTERPOLATION_TOKENS, true)) {
            $found = true;
            $line ??= $token[2];
        }
    }

    return $lines;
}

it('builds every string one way, so a reader never has to parse a quote to find the values', function (): void {
    $files = RepoTree::files(RepoTree::EVERY_PHP_FILE);

    expect(count($files))->toBeGreaterThan(
        INTERPOLATION_FILE_FLOOR,
        'The walk opened '.count($files).' PHP files, so a clean answer here is a walk that read almost nothing.'
    );

    $offenders = [];
    $root = RepoTree::root().'/';

    foreach ($files as $path) {
        // A .blade.php ends in .php, so every PHP walk holds templates, and
        // token_get_all reads one as a single T_INLINE_HTML — reported clean
        // without being read. This seam answers a template with its islands on
        // the lines Blade wrote them, and a PHP file with itself.
        $source = BladePhpSource::forPath($path, (string) file_get_contents($path));

        foreach (interpolatedStringLines($source) as $line) {
            $offenders[] = str_replace($root, '', $path).':'.$line;
        }
    }

    expect($offenders)->toBe([], implode("\n  ", [
        'A double-quoted string interpolates a value. Build it with sprintf so the sentence reads as a sentence',
        'and the values are a list beside it. Heredoc and nowdoc are exempt and unchanged. Offenders:',
        ...array_slice($offenders, 0, 40),
        count($offenders) > 40 ? '… and '.(count($offenders) - 40).' more' : '',
    ]));
});
