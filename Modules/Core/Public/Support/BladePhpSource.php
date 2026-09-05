<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Internal\Support\MarkupLexer;
use Modules\Core\Public\Exceptions\MarkupParseFailedException;

// A Blade file is not PHP until Blade compiles it: `token_get_all` enters PHP
// mode at a literal `<?php` and nowhere else, so an `@php` island, a `{{ }}`
// echo and an `@if` condition all reach a token walk as one T_INLINE_HTML and
// every symbol in them is invisible. This returns the PHP, line for line.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-scanner-blind-inside-a-php-island
 */
final class BladePhpSource
{
    private const array RAW_OPENERS = ['<?php', '<?='];

    private const array ECHO_TAGS = [['{!!', '!!}'], ['{{{', '}}}'], ['{{', '}}']];

    private const string BLOCK_CLOSER = '@endphp';

    /**
     * @return string the PHP $source holds, on the lines the Blade wrote it
     *
     * @throws MarkupParseFailedException when a directive argument or an
     *                                    attribute value never closes, which
     *                                    is a template nothing can read rather
     *                                    than a template holding no code
     */
    public static function of(string $source): string
    {
        $php = '';
        $length = strlen($source);
        $gap = 0;
        $at = 0;

        while ($at < $length) {
            $island = self::islandAt($source, $at, $length);

            if ($island === null) {
                $at++;

                continue;
            }

            [$end, $body] = $island;
            $php .= self::newlinesOf(substr($source, $gap, $at - $gap)).$body;
            $at = $end;
            $gap = $end;
        }

        return $php.self::newlinesOf(substr($source, $gap));
    }

    /**
     * @return string the reading a walk holding both kinds of file wants: a
     *                template answers with its islands, a PHP file with itself
     */
    public static function forPath(string $path, string $source): string
    {
        return str_ends_with($path, '.blade.php') ? self::of($source) : $source;
    }

    /**
     * @return array{0: int, 1: string}|null the offset past the island, and the PHP it holds
     */
    private static function islandAt(string $source, int $at, int $length): ?array
    {
        return match ($source[$at]) {
            '<' => self::rawTagAt($source, $at, $length),
            '{' => self::echoAt($source, $at, $length),
            '@' => self::directiveAt($source, $at, $length),
            default => null,
        };
    }

    /**
     * @return array{0: int, 1: string}|null
     */
    private static function rawTagAt(string $source, int $at, int $length): ?array
    {
        foreach (self::RAW_OPENERS as $opener) {
            if (substr($source, $at, strlen($opener)) !== $opener) {
                continue;
            }

            $end = self::past($source, '?>', $at + strlen($opener), $length);

            return [$end, substr($source, $at, $end - $at)];
        }

        return null;
    }

    // `{!!` is read before `{{{` and `{{{` before `{{`, the order Blade's own
    // echo compilers run in: the shorter opener is a prefix of the longer one
    // and would otherwise swallow it.
    /**
     * @return array{0: int, 1: string}|null
     */
    private static function echoAt(string $source, int $at, int $length): ?array
    {
        if (substr($source, $at, 4) === '{{--') {
            return self::blanked($source, $at, self::past($source, '--}}', $at + 4, $length));
        }

        foreach (self::ECHO_TAGS as [$open, $close]) {
            if (substr($source, $at, strlen($open)) === $open) {
                $end = self::past($source, $close, $at + strlen($open), $length);
                $from = $at + strlen($open);

                return [$end, self::wrapped(substr($source, $from, $end - $from - strlen($close)))];
            }
        }

        return null;
    }

    // The block form is read here and every other directive through MarkupLexer,
    // which already steps over the quotes and comments that put an unbalanced
    // parenthesis inside an argument list.
    /**
     * @return array{0: int, 1: string}|null
     */
    private static function directiveAt(string $source, int $at, int $length): ?array
    {
        $handled = self::escapedAt($source, $at, $length)
            ?? self::verbatimAt($source, $at, $length)
            ?? self::blockAt($source, $at, $length);

        if ($handled !== null) {
            return $handled;
        }

        $end = MarkupLexer::pastConstruct($source, $at, $length);

        return $end === null ? null : self::argumentsAt($source, $at, $end);
    }

    // Blanked rather than read: a @verbatim body is markup the template hands
    // back untouched, so any PHP-looking text inside it is not code.
    /**
     * @return array{0: int, 1: string}|null
     */
    private static function verbatimAt(string $source, int $at, int $length): ?array
    {
        if (strcasecmp(substr($source, $at, 9), '@verbatim') !== 0) {
            return null;
        }

        return self::blanked($source, $at, self::past($source, '@endverbatim', $at + 9, $length));
    }

    // `@php … @endphp` is the one directive whose body is not an argument list,
    // and the one whose closer a template can leave out. An island that never
    // closes still holds the code a guard has to read, so it runs to the end of
    // the file rather than reading as no island at all.
    /**
     * @return array{0: int, 1: string}|null
     */
    private static function blockAt(string $source, int $at, int $length): ?array
    {
        $after = $source[$at + 4] ?? '';

        if (strcasecmp(substr($source, $at, 4), '@php') !== 0 || $after === '_' || ctype_alnum($after)) {
            return null;
        }

        if (($source[MarkupLexer::pastSpace($source, $at + 4, $length)] ?? '') === '(') {
            return null;
        }

        $closer = stripos($source, self::BLOCK_CLOSER, $at + 4);
        $body = $closer === false ? $length : $closer;

        return [
            $closer === false ? $length : $closer + strlen(self::BLOCK_CLOSER),
            self::wrapped(substr($source, $at + 4, $body - $at - 4)),
        ];
    }

    // `@@if` and `@{{ }}` are how Blade writes its own syntax out literally, so
    // what follows either escape is text rather than code.
    /**
     * @return array{0: int, 1: string}|null
     */
    private static function escapedAt(string $source, int $at, int $length): ?array
    {
        $next = $source[$at + 1] ?? '';

        if ($next === '@') {
            return [$at + 2, ''];
        }

        return $next === '{'
            ? self::blanked($source, $at, MarkupLexer::pastConstruct($source, $at + 1, $length) ?? $at + 2)
            : null;
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function argumentsAt(string $source, int $at, int $end): array
    {
        $open = strpos($source, '(', $at);
        $from = $open === false ? $end - 1 : $open + 1;

        return [$end, self::newlinesOf(substr($source, $at, $from - $at)).self::wrapped(substr($source, $from, $end - $from - 1))];
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function blanked(string $source, int $at, int $end): array
    {
        return [$end, self::newlinesOf(substr($source, $at, $end - $at))];
    }

    // Terminated so the tokeniser reads a statement rather than a run-on: an
    // `@if` condition and a `{{ }}` echo are both expressions in the source,
    // and the extra `;` after an `@php` body that already ends in one is an
    // empty statement nothing reads.
    private static function wrapped(string $php): string
    {
        return '<?php '.$php.'; ?>';
    }

    private static function past(string $source, string $closer, int $from, int $length): int
    {
        $at = strpos($source, $closer, $from);

        return $at === false ? $length : $at + strlen($closer);
    }

    private static function newlinesOf(string $markup): string
    {
        return str_repeat("\n", substr_count($markup, "\n"));
    }
}
