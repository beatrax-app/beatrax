<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Every `preg_replace`/`preg_replace_callback`/`preg_split` call in product
// code, and what the call site does with the value PCRE handed back. Tokenised
// rather than matched with a regex, because the thing being looked for is a
// regex call: a pattern written inside a string, a name inside a comment and a
// method of the same name all read alike to `grep`.
/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#a-replace-that-never-ran-blanks-the-subject
 */
final class ReplaceReturnSites
{
    public const array SCANNED_FUNCTIONS = ['preg_replace', 'preg_replace_callback', 'preg_split'];

    // The one home for the checked reading. Its own calls are the checked
    // ones, so it is the single file the guard steps over.
    public const string SEAM = 'Modules/Core/Public/Support/PatternScan.php';

    private const array EMPTY_FALLBACKS = ["''", '""', 'null', '[', 'array'];

    private const array NAMES_THE_FAILURE = ['null', 'false'];

    private const array TYPE_TESTS = ['is_string', 'is_array'];

    private const array PRODUCTION_ROOTS = ['Modules', 'app', 'database', 'routes', 'config', 'bootstrap'];

    /**
     * The guard tree reads its own subjects and is held to the same rule by
     * `AStoppedScanIsNeverReadAsAnEmptyOneArchTest`, which owns that walk. This
     * one covers the code that ships.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [];

        foreach (self::PRODUCTION_ROOTS as $root) {
            $dir = base_path($root);
            if (! is_dir($dir)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file) {
                $path = $file->getPathname();

                if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/vendor/') && ! str_contains($path, '/tests/')) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The calls in $source whose failure value can reach the program as an
     * ordinary empty answer.
     *
     * @return list<array{line: int, call: string, followedBy: string}>
     */
    public static function uncheckedIn(string $source): array
    {
        $tokens = self::significantTokens($source);
        $found = [];

        foreach ($tokens as $index => $token) {
            $open = self::callOpensAt($tokens, $index);
            if ($open === null) {
                continue;
            }

            $close = self::closingParen($tokens, $open);
            if (self::separatesTheFailure($tokens, $index, $close)) {
                continue;
            }

            $found[] = [
                'line' => $token['line'],
                'call' => $token['text'],
                'followedBy' => trim(self::textAt($tokens, $index - 1).' … '.self::textAt($tokens, $close + 1).' '.self::textAt($tokens, $close + 2)),
            ];
        }

        return $found;
    }

    /**
     * A walk that read nothing reports the same clean tree as a walk that found
     * nothing, so the caller asserts this denominator before it trusts a verdict.
     */
    public static function callsIn(string $source): int
    {
        $tokens = self::significantTokens($source);
        $calls = 0;

        foreach (array_keys($tokens) as $index) {
            $calls += self::callOpensAt($tokens, $index) === null ? 0 : 1;
        }

        return $calls;
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @return int|null the index of the call's opening paren
     */
    private static function callOpensAt(array $tokens, int $index): ?int
    {
        $token = $tokens[$index];

        if ($token['id'] !== T_STRING || ! in_array(strtolower($token['text']), self::SCANNED_FUNCTIONS, true)) {
            return null;
        }

        $before = $tokens[$index - 1] ?? null;
        $namesAMember = $before !== null
            && (in_array($before['text'], ['->', '?->', '::'], true) || $before['id'] === T_FUNCTION);

        if ($namesAMember || ($tokens[$index + 1]['text'] ?? '') !== '(') {
            return null;
        }

        return $index + 1;
    }

    /**
     * `preg_replace` returns null and `preg_split` returns false only when PCRE
     * gave up, so any reading that names that value keeps the two apart. A
     * `(string)` or `(array)` cast and a fallback to an empty literal do the
     * opposite: they spell the give-up as an ordinary empty answer.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function separatesTheFailure(array $tokens, int $index, int $close): bool
    {
        if (in_array($tokens[$index - 1]['id'] ?? null, [T_STRING_CAST, T_ARRAY_CAST], true)) {
            return false;
        }

        return self::fallbackNamesAValue($tokens, $close)
            || self::comparedWithTheFailure($tokens, $index, $close)
            || self::wrappedInATypeTest($tokens, $index)
            || self::assignedToATestedVariable($tokens, $index, $close);
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function fallbackNamesAValue(array $tokens, int $close): bool
    {
        $elvis = self::textAt($tokens, $close + 1) === '?' && self::textAt($tokens, $close + 2) === ':';

        if (! $elvis && self::textAt($tokens, $close + 1) !== '??') {
            return false;
        }

        $fallback = strtolower(self::textAt($tokens, $close + ($elvis ? 3 : 2)));

        return ! in_array($fallback, self::EMPTY_FALLBACKS, true);
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function comparedWithTheFailure(array $tokens, int $index, int $close): bool
    {
        return self::isComparison(self::textAt($tokens, $close + 1), self::textAt($tokens, $close + 2))
            || self::isComparison(self::textAt($tokens, $index - 1), self::textAt($tokens, $index - 2));
    }

    private static function isComparison(string $operator, string $operand): bool
    {
        return in_array($operator, ['===', '!=='], true)
            && in_array(strtolower($operand), self::NAMES_THE_FAILURE, true);
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function wrappedInATypeTest(array $tokens, int $index): bool
    {
        return self::textAt($tokens, $index - 1) === '('
            && in_array(strtolower(self::textAt($tokens, $index - 2)), self::TYPE_TESTS, true);
    }

    /**
     * A `$x = preg_replace(…)` says nothing on its own; the reading that
     * matters is whatever the code does with `$x` afterwards, and that is
     * routinely a line or two below — often past the `if`/`else` that assigned
     * it. Bounded by the next `function`, which is where a variable of the same
     * name stops being this one.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function assignedToATestedVariable(array $tokens, int $index, int $close): bool
    {
        if (self::textAt($tokens, $index - 1) !== '=' || ($tokens[$index - 2]['id'] ?? null) !== T_VARIABLE) {
            return false;
        }

        $name = self::textAt($tokens, $index - 2);

        for ($i = $close + 1, $total = count($tokens); $i < $total; $i++) {
            if (in_array($tokens[$i]['id'], [T_FUNCTION, T_FN], true)) {
                return false;
            }

            if ($tokens[$i]['id'] === T_VARIABLE && $tokens[$i]['text'] === $name && self::testsTheVariable($tokens, $i)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function testsTheVariable(array $tokens, int $at): bool
    {
        if (self::isComparison(self::textAt($tokens, $at + 1), self::textAt($tokens, $at + 2))) {
            return true;
        }

        if (self::isComparison(self::textAt($tokens, $at - 1), self::textAt($tokens, $at - 2))) {
            return true;
        }

        if (in_array(self::textAt($tokens, $at + 1), ['??=', '??'], true)) {
            return true;
        }

        return self::wrappedInATypeTest($tokens, $at);
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function textAt(array $tokens, int $index): string
    {
        return $tokens[$index]['text'] ?? '';
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function closingParen(array $tokens, int $open): int
    {
        $depth = 0;

        for ($i = $open, $total = count($tokens); $i < $total; $i++) {
            $text = $tokens[$i]['text'];

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return count($tokens) - 1;
    }

    /**
     * @return list<array{id: int|null, text: string, line: int}>
     */
    private static function significantTokens(string $source): array
    {
        $significant = [];
        $line = 1;

        foreach (token_get_all($source) as $token) {
            if (is_string($token)) {
                $significant[] = ['id' => null, 'text' => $token, 'line' => $line];

                continue;
            }

            $line = $token[2];

            if (in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $significant[] = ['id' => $token[0], 'text' => $token[1], 'line' => $line];
        }

        return $significant;
    }
}
