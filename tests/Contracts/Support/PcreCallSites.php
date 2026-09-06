<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

// The reading two guards share: find the direct calls to a set of PCRE
// functions in a source file, and decide what the call site did with the value
// PCRE handed back. Tokenised rather than matched with a regex, because the
// thing being looked for is a regex call: a pattern written inside a string, a
// name inside a comment and a method of the same name all read alike to `grep`,
// and the guard that reported a wrong answer in the first place was a regex
// over source.
//
// What each guard accepts differs -- `=== 1` reads a matcher, `=== null` reads
// a replacer -- so the predicate stays with the guard and only the walk to the
// tokens it has to judge lives here.
final class PcreCallSites
{
    /**
     * @return list<array{id: int|null, text: string, line: int}>
     */
    public static function significantTokens(string $source): array
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

    /**
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  list<string>  $names
     * @return int|null the index of the call's opening paren
     */
    public static function callOpensAt(array $tokens, int $index, array $names): ?int
    {
        $token = $tokens[$index];

        // PHP 8 hands back a leading-backslash call as one T_NAME_FULLY_QUALIFIED
        // token spelled `\preg_match`, so a reader keyed on T_STRING alone cannot
        // see the one spelling a contributor reaches for to escape the rule.
        if (! in_array($token['id'], [T_STRING, T_NAME_FULLY_QUALIFIED], true)) {
            return null;
        }

        if (! in_array(strtolower(ltrim($token['text'], '\\')), $names, true)) {
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
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function closingParen(array $tokens, int $open): int
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
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     */
    public static function textAt(array $tokens, int $index): string
    {
        return $tokens[$index]['text'] ?? '';
    }

    /**
     * A `$x = preg_match(…)` says nothing on its own; the reading that matters
     * is whatever the code does with `$x` afterwards, and that is routinely a
     * line or two below — often past the `if`/`else` that assigned it. Bounded
     * by the next `function`, which is where a variable of the same name stops
     * being this one. $readsTheAnswer is asked of every later use, and answers
     * whether that use separates a give-up from an empty answer.
     *
     * @param  list<array{id: int|null, text: string, line: int}>  $tokens
     * @param  callable(list<array{id: int|null, text: string, line: int}>, int): bool  $readsTheAnswer
     */
    public static function assignedToATestedVariable(array $tokens, int $index, int $close, callable $readsTheAnswer): bool
    {
        if (self::textAt($tokens, $index - 1) !== '=' || ($tokens[$index - 2]['id'] ?? null) !== T_VARIABLE) {
            return false;
        }

        $name = self::textAt($tokens, $index - 2);

        for ($i = $close + 1, $total = count($tokens); $i < $total; $i++) {
            if (in_array($tokens[$i]['id'], [T_FUNCTION, T_FN], true)) {
                return false;
            }

            if ($tokens[$i]['id'] === T_VARIABLE && $tokens[$i]['text'] === $name && $readsTheAnswer($tokens, $i)) {
                return true;
            }
        }

        return false;
    }
}
