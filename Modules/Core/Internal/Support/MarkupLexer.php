<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

use Modules\Core\Public\Exceptions\MarkupParseFailedException;

// A character walk rather than a pattern, because the thing being read is the
// thing patterns get wrong: `[^>]*` ends a tag at the first `>`, and an Alpine
// expression, a directive argument and an `=>` inside `@class` all put one
// inside an attribute. Quotes, echoes and parentheses are stepped over here.
final class MarkupLexer
{
    private const VOID_ELEMENTS = ['area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr'];

    private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea'];

    /**
     * @return list<MarkupToken>
     */
    public static function tokens(string $source): array
    {
        $tokens = [];
        $length = strlen($source);
        $at = 0;

        while ($at < $length) {
            $at = self::step($source, $length, $at, $tokens);
        }

        return $tokens;
    }

    public static function text(string $source): string
    {
        $visible = '';
        $length = strlen($source);
        $at = 0;

        while ($at < $length) {
            $next = self::skipMarkupAt($source, $length, $at);
            $visible .= $next === null ? $source[$at] : '';
            $at = $next ?? $at + 1;
        }

        return $visible;
    }

    public static function tagEnd(string $source, int $from, int $length): int
    {
        $at = $from;

        while ($at < $length) {
            if ($source[$at] === '>') {
                return $at;
            }

            $at = self::pastConstruct($source, $at, $length) ?? $at + 1;
        }

        throw new MarkupParseFailedException('a start tag that never closes', self::excerpt($source, $from));
    }

    public static function pastConstruct(string $source, int $at, int $length): ?int
    {
        $character = $source[$at];

        if ($character === '"' || $character === "'") {
            return self::pastQuote($source, $character, $at, $length);
        }

        if ($character === '{') {
            return self::pastEcho($source, $at, $length);
        }

        return $character === '@' ? self::pastDirective($source, $at, $length) : null;
    }

    public static function pastQuote(string $source, string $quote, int $at, int $length): int
    {
        $closed = strpos($source, $quote, $at + 1);

        if ($closed === false) {
            throw new MarkupParseFailedException('an attribute value with no closing quote', self::excerpt($source, $at));
        }

        return $closed + 1;
    }

    public static function pastSpace(string $source, int $at, int $length): int
    {
        while ($at < $length && ctype_space($source[$at])) {
            $at++;
        }

        return $at;
    }

    public static function nameAt(string $source, int $from): string
    {
        $length = strlen($source);

        if ($from >= $length || ctype_alpha($source[$from]) === false) {
            return '';
        }

        $at = $from;
        while ($at < $length && ! ctype_space($source[$at]) && $source[$at] !== '>' && $source[$at] !== '/') {
            $at++;
        }

        return substr($source, $from, $at - $from);
    }

    /**
     * @param  list<MarkupToken>  $tokens
     */
    private static function step(string $source, int $length, int $at, array &$tokens): int
    {
        $character = $source[$at];

        if ($character === '{' || $character === '@') {
            return self::pastConstruct($source, $at, $length) ?? $at + 1;
        }

        return $character === '<'
            ? self::readTag($source, $length, $at, $tokens)
            : $at + 1;
    }

    /**
     * @param  list<MarkupToken>  $tokens
     */
    private static function readTag(string $source, int $length, int $at, array &$tokens): int
    {
        if (str_starts_with(substr($source, $at, 4), '<!--')) {
            return self::past($source, '-->', $at + 4, $length);
        }

        $closing = ($source[$at + 1] ?? '') === '/';
        $name = self::nameAt($source, $at + ($closing ? 2 : 1));

        return $name === ''
            ? $at + 1
            : self::recordTag($source, $length, $at, $name, $closing, $tokens);
    }

    /**
     * @param  list<MarkupToken>  $tokens
     */
    private static function recordTag(string $source, int $length, int $at, string $name, bool $closing, array &$tokens): int
    {
        $end = self::tagEnd($source, $at + 1, $length);
        $empty = $source[$end - 1] === '/' || in_array(strtolower($name), self::VOID_ELEMENTS, true);
        $tokens[] = new MarkupToken($name, $closing, $empty, $at, $end);

        return self::afterTag($source, $length, $end, $name, $closing || $empty);
    }

    // Script and style hold characters that are not markup at all, so the walk
    // jumps their bodies whole: a `<` inside a comparison would otherwise open
    // an element that never closes.
    private static function afterTag(string $source, int $length, int $end, string $name, bool $selfContained): int
    {
        if ($selfContained || ! in_array(strtolower($name), self::RAW_TEXT_ELEMENTS, true)) {
            return $end + 1;
        }

        $close = stripos($source, '</'.$name, $end + 1);

        return $close === false ? $end + 1 : $close;
    }

    private static function skipMarkupAt(string $source, int $length, int $at): ?int
    {
        if (str_starts_with(substr($source, $at, 4), '{{--')) {
            return self::past($source, '--}}', $at + 4, $length);
        }

        if ($source[$at] === '@') {
            return self::pastDirective($source, $at, $length);
        }

        return $source[$at] === '<' ? self::skipTagAt($source, $length, $at) : null;
    }

    private static function skipTagAt(string $source, int $length, int $at): ?int
    {
        if (str_starts_with(substr($source, $at, 4), '<!--')) {
            return self::past($source, '-->', $at + 4, $length);
        }

        $closing = ($source[$at + 1] ?? '') === '/';
        $name = self::nameAt($source, $at + ($closing ? 2 : 1));

        return $name === ''
            ? null
            : self::afterTag($source, $length, self::tagEnd($source, $at + 1, $length), $name, $closing);
    }

    private static function pastEcho(string $source, int $at, int $length): ?int
    {
        $opener = substr($source, $at, 4);

        if (str_starts_with($opener, '{{--')) {
            return self::past($source, '--}}', $at + 4, $length);
        }

        if (str_starts_with($opener, '{!!')) {
            return self::past($source, '!!}', $at + 3, $length);
        }

        return str_starts_with($opener, '{{') ? self::past($source, '}}', $at + 2, $length) : null;
    }

    // Only a directive carrying PHP is stepped over. `@click="…"` is an Alpine
    // attribute wearing the same first character, and it has to stay visible to
    // the attribute reader. A `@php` body is prose and type syntax as often as
    // it is code, and a `<script>` named in one of its docblocks is not markup.
    private static function pastDirective(string $source, int $at, int $length): ?int
    {
        $end = $at + 1;
        while ($end < $length && ctype_alpha($source[$end])) {
            $end++;
        }

        $after = self::pastSpace($source, $end, $length);

        if (($source[$after] ?? '') === '(' && $end > $at + 1) {
            return self::pastParens($source, $after, $length);
        }

        return substr($source, $at, $end - $at) === '@php'
            ? self::pastBlock($source, '@endphp', $end)
            : null;
    }

    private static function pastBlock(string $source, string $closer, int $from): ?int
    {
        $at = stripos($source, $closer, $from);

        return $at === false ? null : $at + strlen($closer);
    }

    // A directive argument is PHP, so its comments are PHP comments, and an
    // apostrophe in one of them would otherwise open a string that runs past
    // the closing parenthesis and takes the rest of the file with it.
    private static function pastParens(string $source, int $from, int $length): int
    {
        $depth = 0;
        $at = $from;

        while ($at < $length) {
            $skipped = self::pastPhpAside($source, $at, $length);

            if ($skipped !== null) {
                $at = $skipped;

                continue;
            }

            $depth += ($source[$at] === '(' ? 1 : 0) - ($source[$at] === ')' ? 1 : 0);
            $at++;

            if ($depth === 0) {
                return $at;
            }
        }

        throw new MarkupParseFailedException('a directive with no closing parenthesis', self::excerpt($source, $from));
    }

    private static function pastPhpAside(string $source, int $at, int $length): ?int
    {
        $character = $source[$at];

        if ($character === '"' || $character === "'") {
            return self::pastQuote($source, $character, $at, $length);
        }

        if ($character !== '/') {
            return null;
        }

        $next = $source[$at + 1] ?? '';

        return match ($next) {
            '/' => self::pastBlock($source, "\n", $at + 2) ?? $length,
            '*' => self::past($source, '*/', $at + 2, $length),
            default => null,
        };
    }

    private static function past(string $source, string $closer, int $from, int $length): int
    {
        $at = strpos($source, $closer, $from);

        return $at === false ? $length : $at + strlen($closer);
    }

    private static function excerpt(string $source, int $at): string
    {
        return str_replace("\n", ' ', substr($source, max(0, $at - 1), 60));
    }
}
