<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

// Every `preg_replace`/`preg_replace_callback`/`preg_split` call in product
// code, and what the call site does with the value PCRE handed back. The walk
// itself is PcreCallSites; this class is the reading a replacer's answer has
// to survive.
/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#a-replace-that-never-ran-blanks-the-subject
 */
final class ReplaceReturnSites
{
    public const array SCANNED_FUNCTIONS = ['preg_replace', 'preg_replace_callback', 'preg_split'];

    private const array EMPTY_FALLBACKS = ["''", '""', 'null', '[', 'array'];

    private const array NAMES_THE_FAILURE = ['null', 'false'];

    private const array TYPE_TESTS = ['is_string', 'is_array'];

    /**
     * The guard tree reads its own subjects and is held to the same rule by
     * `AStoppedScanIsNeverReadAsAnEmptyOneArchTest`, which owns that walk. This
     * one covers the code that ships, and it means all of it: the six roots
     * this list used to name left out scripts/, where five casts were blanking
     * the Android manifest a build at a time.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        return RepoTree::files(RepoTree::PRODUCTION_PHP);
    }

    /**
     * The calls in $source whose failure value can reach the program as an
     * ordinary empty answer.
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
            if (self::separatesTheFailure($tokens, $index, $close)) {
                continue;
            }

            $found[] = [
                'line' => $token['line'],
                'call' => $token['text'],
                'followedBy' => trim(PcreCallSites::textAt($tokens, $index - 1).' … '.PcreCallSites::textAt($tokens, $close + 1).' '.PcreCallSites::textAt($tokens, $close + 2)),
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
        $tokens = PcreCallSites::significantTokens($source);
        $calls = 0;

        foreach (array_keys($tokens) as $index) {
            $calls += PcreCallSites::callOpensAt($tokens, $index, self::SCANNED_FUNCTIONS) === null ? 0 : 1;
        }

        return $calls;
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
            || PcreCallSites::assignedToATestedVariable($tokens, $index, $close, self::testsTheVariable(...));
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function fallbackNamesAValue(array $tokens, int $close): bool
    {
        $elvis = PcreCallSites::textAt($tokens, $close + 1) === '?' && PcreCallSites::textAt($tokens, $close + 2) === ':';

        if (! $elvis && PcreCallSites::textAt($tokens, $close + 1) !== '??') {
            return false;
        }

        $fallback = strtolower(PcreCallSites::textAt($tokens, $close + ($elvis ? 3 : 2)));

        return ! in_array($fallback, self::EMPTY_FALLBACKS, true);
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function comparedWithTheFailure(array $tokens, int $index, int $close): bool
    {
        return self::isComparison(PcreCallSites::textAt($tokens, $close + 1), PcreCallSites::textAt($tokens, $close + 2))
            || self::isComparison(PcreCallSites::textAt($tokens, $index - 1), PcreCallSites::textAt($tokens, $index - 2));
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
        return PcreCallSites::textAt($tokens, $index - 1) === '('
            && in_array(strtolower(PcreCallSites::textAt($tokens, $index - 2)), self::TYPE_TESTS, true);
    }

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    private static function testsTheVariable(array $tokens, int $at): bool
    {
        if (self::isComparison(PcreCallSites::textAt($tokens, $at + 1), PcreCallSites::textAt($tokens, $at + 2))) {
            return true;
        }

        if (self::isComparison(PcreCallSites::textAt($tokens, $at - 1), PcreCallSites::textAt($tokens, $at - 2))) {
            return true;
        }

        if (in_array(PcreCallSites::textAt($tokens, $at + 1), ['??=', '??'], true)) {
            return true;
        }

        return self::wrappedInATypeTest($tokens, $at);
    }
}
