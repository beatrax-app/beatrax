<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Support;

final class MarkupAttributes
{
    /**
     * @return array<string, string>
     */
    public static function of(string $startTag): array
    {
        $length = strlen($startTag);
        $at = 1 + strlen(MarkupLexer::nameAt($startTag, 1));
        $attributes = [];

        while ($at < $length) {
            $at = self::step($startTag, $length, $at, $attributes);
        }

        return $attributes;
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function step(string $tag, int $length, int $at, array &$attributes): int
    {
        $character = $tag[$at];

        if (ctype_space($character) || $character === '>' || $character === '/') {
            return $at + 1;
        }

        $construct = $character === '@' || $character === '{'
            ? MarkupLexer::pastConstruct($tag, $at, $length)
            : null;

        return $construct ?? self::readPair($tag, $length, $at, $attributes);
    }

    /**
     * @param  array<string, string>  $attributes
     */
    private static function readPair(string $tag, int $length, int $at, array &$attributes): int
    {
        $end = self::nameEnd($tag, $length, $at);
        $name = substr($tag, $at, $end - $at);
        $after = MarkupLexer::pastSpace($tag, $end, $length);

        if (($tag[$after] ?? '') !== '=') {
            $attributes[$name] = '';

            return $end;
        }

        [$value, $next] = self::readValue($tag, $length, MarkupLexer::pastSpace($tag, $after + 1, $length));
        $attributes[$name] = $value;

        return $next;
    }

    private static function nameEnd(string $tag, int $length, int $at): int
    {
        $end = $at + 1;

        while ($end < $length && ! ctype_space($tag[$end]) && ! in_array($tag[$end], ['=', '>', '/'], true)) {
            $end++;
        }

        return $end;
    }

    /**
     * @return array{0: string, 1: int}
     */
    private static function readValue(string $tag, int $length, int $at): array
    {
        $quote = $tag[$at] ?? '';

        if ($quote === '"' || $quote === "'") {
            $end = MarkupLexer::pastQuote($tag, $quote, $at, $length);

            return [substr($tag, $at + 1, $end - $at - 2), $end];
        }

        $end = $at;
        while ($end < $length && ! ctype_space($tag[$end]) && $tag[$end] !== '>') {
            $end++;
        }

        return [substr($tag, $at, $end - $at), $end];
    }
}
