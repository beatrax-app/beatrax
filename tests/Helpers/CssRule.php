<?php

declare(strict_types=1);

namespace Tests\Helpers;

// Reads the compiled shape of a stylesheet rule for the arch tests that assert
// on one. Both of them previously took a fixed-length window after the
// selector, which answers about whatever happens to sit at that offset: too
// short and a present declaration reads as missing, too long and a neighbour's
// declarations answer in its place.
final class CssRule
{
    // Comments are blanked rather than removed, so every offset still lines up
    // with the caller's string. They carry braces of their own and sit directly
    // above the rule they explain, which both corrupts the block depth and puts
    // a comment at the head of the next prelude; blanking also stops an
    // assertion matching a declaration that is only quoted in prose.
    private static function withoutComments(string $css): string
    {
        return (string) preg_replace_callback(
            '~/\*.*?\*/~s',
            static fn (array $match): string => str_repeat(' ', strlen($match[0])),
            $css,
        );
    }

    // The declaration block belonging to $selector, braces included, matched by
    // depth so a nested at-rule inside it does not end the block early.
    public static function blockFor(string $css, string $selector): string
    {
        $css = self::withoutComments($css);
        $selectorAt = strpos($css, $selector);
        if ($selectorAt === false) {
            return '';
        }

        $open = strpos($css, '{', $selectorAt);
        if ($open === false) {
            return '';
        }

        $depth = 0;
        $length = strlen($css);
        for ($cursor = $open; $cursor < $length; $cursor++) {
            $depth += (int) ($css[$cursor] === '{') - (int) ($css[$cursor] === '}');
            if ($depth === 0) {
                return substr($css, $open, $cursor - $open + 1);
            }
        }

        return '';
    }

    // The selector list a rule is declared with, for asserting that a shared
    // rule still names a given selector. Read back from the brace that closed
    // the previous rule rather than from the match, so every sibling selector
    // in the list is returned and not just the tail after $selector.
    public static function selectorListFor(string $css, string $selector): string
    {
        $css = self::withoutComments($css);
        $selectorAt = strpos($css, $selector);
        if ($selectorAt === false) {
            return '';
        }

        $open = strpos($css, '{', $selectorAt);
        if ($open === false) {
            return '';
        }

        $previous = strrpos(substr($css, 0, $selectorAt), '}');
        $start = $previous === false ? 0 : $previous + 1;

        return substr($css, $start, $open - $start);
    }

    // The prelude of the innermost at-rule whose block contains $selector, e.g.
    // `@media (pointer: coarse)`. Found by tracking block depth rather than by
    // searching backwards for the nearest `@media`, which lands on the last one
    // that CLOSED before the selector just as readily as on the one holding it.
    public static function atRuleEnclosing(string $css, string $selector): string
    {
        $css = self::withoutComments($css);
        $target = strpos($css, $selector);
        if ($target === false) {
            return '';
        }

        /** @var list<string> $open */
        $open = [];
        $preludeStart = 0;

        for ($cursor = 0; $cursor < $target; $cursor++) {
            $character = $css[$cursor];

            if ($character === '{') {
                $open[] = trim(substr($css, $preludeStart, $cursor - $preludeStart));
                $preludeStart = $cursor + 1;

                continue;
            }

            if ($character === '}') {
                array_pop($open);
                $preludeStart = $cursor + 1;

                continue;
            }

            if ($character === ';') {
                $preludeStart = $cursor + 1;
            }
        }

        for ($depth = count($open) - 1; $depth >= 0; $depth--) {
            if (str_starts_with($open[$depth], '@')) {
                return $open[$depth];
            }
        }

        return '';
    }
}
