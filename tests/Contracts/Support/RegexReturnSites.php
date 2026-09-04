<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Every `preg_match`/`preg_match_all` call in the tree, and what the call site
// does with the value PCRE handed back. Tokenised rather than matched with a
// regex, because the thing being looked for is a regex call: a pattern written
// inside a string, a name inside a comment and a method of the same name all
// read alike to `grep`, and the guard that reported a wrong answer in the first
// place was a regex over source.
final class RegexReturnSites
{
    public const array SCANNED_FUNCTIONS = ['preg_match', 'preg_match_all'];

    // The one home for the checked reading. Its own calls are the checked
    // ones, so it is the single file the guard steps over.
    public const string SEAM = 'Modules/Core/Public/Support/PatternScan.php';

    /**
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [];

        foreach (['Modules', 'app', 'tests', 'database', 'scripts'] as $root) {
            $dir = base_path($root);
            if (! is_dir($dir)) {
                continue;
            }

            /** @var SplFileInfo $file */
            foreach (new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            ) as $file) {
                $path = $file->getPathname();

                if ($file->isFile() && str_ends_with($path, '.php') && ! str_contains($path, '/vendor/')) {
                    $files[] = $path;
                }
            }
        }

        sort($files);

        return $files;
    }

    /**
     * The calls in $source whose return is neither compared identically with
     * `1` nor with `false`, keyed nowhere — the caller reports them as found.
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
            if (self::isGuarded($tokens, $index, $close)) {
                continue;
            }

            $found[] = [
                'line' => $token['line'],
                'call' => $token['text'],
                'followedBy' => trim(($tokens[$close + 1]['text'] ?? ';').' '.($tokens[$close + 2]['text'] ?? '')),
            ];
        }

        return $found;
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
     * An identical comparison with `1` or with `false` is the whole of the
     * contract: both readings separate "PCRE gave up" from "PCRE found
     * nothing", which `> 0`, `(bool)`, `!` and a bare `if` all conflate.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isGuarded(array $tokens, int $index, int $close): bool
    {
        return self::comparesToOneOrFalse($tokens[$close + 1] ?? null, $tokens[$close + 2] ?? null)
            || self::comparesToOneOrFalse($tokens[$index - 1] ?? null, $tokens[$index - 2] ?? null);
    }

    /**
     * @param  array{id: int|null, text: string, line: int}|null  $operator
     * @param  array{id: int|null, text: string, line: int}|null  $operand
     */
    private static function comparesToOneOrFalse(?array $operator, ?array $operand): bool
    {
        if ($operator === null || $operand === null || ! in_array($operator['text'], ['===', '!=='], true)) {
            return false;
        }

        return $operand['text'] === '1' || strtolower($operand['text']) === 'false';
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
