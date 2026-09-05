<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// Every `preg_match`/`preg_match_all` call in the tree, and what the call site
// does with the value PCRE handed back. The walk itself is PcreCallSites; this
// class is the reading a matcher's answer has to survive.
final class RegexReturnSites
{
    public const array SCANNED_FUNCTIONS = ['preg_match', 'preg_match_all'];

    /**
     * Every directory under the application root that holds PHP. The list is
     * written out rather than discovered so a new root is a reviewed line
     * rather than a silent widening -- and `unscannedRootsHoldingPhp()` is
     * what stops it from being a silent *narrowing* instead.
     *
     * @var list<string>
     */
    public const array SCANNED_ROOTS = [
        '.claude',
        'app',
        'bootstrap',
        'config',
        'database',
        'lang',
        'Modules',
        'public',
        'resources',
        'routes',
        'scripts',
        'tests',
        'tools',
    ];

    /**
     * Directories the walk steps over, each for a reason that is not "it has
     * no PHP in it today". `mobile-app` is the second Composer root: the
     * mobile-app job runs this same guard with `base_path()` pointing there,
     * so scanning it from here would judge it twice and, through its symlinked
     * `tests`, walk this root's own files a second time.
     *
     * @var list<string>
     */
    public const array ROOTS_COVERED_ELSEWHERE = [
        'mobile-app',
        'node_modules',
        'storage',
        'vendor',
    ];

    // The one home for the checked reading. Its own calls are the checked
    // ones, so it is the single file the guard steps over.
    public const string SEAM = 'Modules/Core/Public/Support/PatternScan.php';

    /**
     * @return list<string>
     */
    public static function files(): array
    {
        $files = [];

        foreach (self::SCANNED_ROOTS as $root) {
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
        $tokens = PcreCallSites::significantTokens($source);
        $found = [];

        foreach ($tokens as $index => $token) {
            $open = PcreCallSites::callOpensAt($tokens, $index, self::SCANNED_FUNCTIONS);
            if ($open === null) {
                continue;
            }

            $close = PcreCallSites::closingParen($tokens, $open);
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
     * An identical comparison with `1` or with `false` is the whole of the
     * contract: both readings separate "PCRE gave up" from "PCRE found
     * nothing", which `> 0`, `(bool)`, `!` and a bare `if` all conflate.
     * Whether that comparison sits against the call or against a variable the
     * call was assigned to is a matter of line length, not of safety.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function isGuarded(array $tokens, int $index, int $close): bool
    {
        return self::separatesTheFailureAt($tokens, $close + 1, $index - 1)
            || PcreCallSites::assignedToATestedVariable($tokens, $index, $close, self::testsTheVariable(...));
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function testsTheVariable(array $tokens, int $at): bool
    {
        return self::separatesTheFailureAt($tokens, $at + 1, $at - 1);
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function separatesTheFailureAt(array $tokens, int $after, int $before): bool
    {
        return self::comparesToOneOrFalse($tokens[$after] ?? null, $tokens[$after + 1] ?? null)
            || self::comparesToOneOrFalse($tokens[$before] ?? null, $tokens[$before - 1] ?? null);
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
     * Top-level directories that hold PHP and that `files()` never opens.
     *
     * A regex guard is only as wide as its walk, and this walk was five names
     * long while the tree had grown thirteen. The rule it enforces is not
     * "these roots are scanned" but "a directory holding PHP is either scanned
     * or named as somebody else's to scan", which is the only form that
     * survives a directory being added.
     *
     * @return list<string>
     */
    public static function unscannedRootsHoldingPhp(): array
    {
        $unscanned = [];

        foreach ((array) scandir(base_path()) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..') {
                continue;
            }

            if (in_array($entry, self::SCANNED_ROOTS, true) || in_array($entry, self::ROOTS_COVERED_ELSEWHERE, true)) {
                continue;
            }

            $dir = base_path($entry);

            if (is_dir($dir) && ! is_link($dir) && self::holdsPhp($dir)) {
                $unscanned[] = $entry;
            }
        }

        sort($unscanned);

        return $unscanned;
    }

    private static function holdsPhp(string $dir): bool
    {
        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && str_ends_with($file->getPathname(), '.php')) {
                return true;
            }
        }

        return false;
    }
}
