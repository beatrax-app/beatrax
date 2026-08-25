<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

// app.css as the cascade reads it once the layers are discounted. A rule that
// has to beat a Tailwind utility — `whitespace-nowrap`, `h-10`, `w-20` — cannot
// live inside a layer, so every guard that pins one of those rules has to read
// the file with the layered blocks taken out. Three of them were about to carry
// the same twenty lines.
final class UnlayeredCss
{
    public static function read(): string
    {
        $css = (string) file_get_contents(base_path('resources/css/app.css'));

        $out = '';
        $offset = 0;
        while (preg_match('/@layer\s+[a-z]+\s*\{/', $css, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $out .= substr($css, $offset, $match[0][1] - $offset);

            $cursor = $match[0][1] + strlen($match[0][0]);
            $depth = 1;
            while ($depth > 0 && $cursor < strlen($css)) {
                if ($css[$cursor] === '{') {
                    $depth++;
                } elseif ($css[$cursor] === '}') {
                    $depth--;
                }
                $cursor++;
            }
            $offset = $cursor;
        }

        return $out.substr($css, $offset);
    }

    /** @return ?string the selector list and declarations that follow $anchor, up to the closing brace */
    public static function ruleAt(string $anchor): ?string
    {
        $css = self::read();

        $start = strpos($css, $anchor);
        if ($start === false) {
            return null;
        }

        $end = strpos($css, '}', $start);

        return $end === false ? null : substr($css, $start, $end - $start);
    }

    // A selector part way down a list identifies the rule; ruleAt() would
    // return only the tail of the list that follows it. This walks back to
    // whatever closed the rule before, so the whole selector list is in scope.
    /** @return ?string the entire rule some part of whose selector list is $anchor */
    public static function ruleWith(string $anchor): ?string
    {
        $css = self::read();

        $found = strpos($css, $anchor);
        if ($found === false) {
            return null;
        }

        $end = strpos($css, '}', $found);
        if ($end === false) {
            return null;
        }

        $start = 0;
        foreach (['}', '*/', '{'] as $boundary) {
            $at = strrpos(substr($css, 0, $found), $boundary);
            if ($at !== false) {
                $start = max($start, $at + strlen($boundary));
            }
        }

        return substr($css, $start, $end - $start);
    }
}
