<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

// Every `preg_match`/`preg_match_all` call in the tree, and what the call site
// does with the value PCRE handed back. The walk itself is PcreCallSites; this
// class is the reading a matcher's answer has to survive.
final class RegexReturnSites
{
    public const array SCANNED_FUNCTIONS = ['preg_match', 'preg_match_all'];

    // The one home for the checked reading. Its own calls are the checked
    // ones, so it is the single file the guard steps over.
    public const string SEAM = 'Modules/Core/Public/Support/PatternScan.php';

    /** @return list<string> */
    public static function files(): array
    {
        return RepoTree::files(RepoTree::EVERY_PHP_FILE);
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
}
