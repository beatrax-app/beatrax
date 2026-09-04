<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Modules\Core\Internal\Support\MarkupLexer;
use Modules\Core\Internal\Support\MarkupToken;

// Template source, not a document: Blade is not HTML and an HTML5 parser proves
// it, relocating an `<x-core::th>` out of the `<table>` it was written inside
// and dropping an `<input>` that carries `@class([...])`. Use RenderedMarkup
// for a response body and this for a file on disk.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-guard-that-reads-html-with-a-regex
 */
final class MarkupSource
{
    /**
     * @return list<MarkupElement>
     */
    public static function elements(string $source, string $name): array
    {
        $wanted = strtolower($name);

        return self::build($source, static fn (MarkupToken $token): bool => strtolower($token->name) === $wanted);
    }

    /**
     * @return list<MarkupElement>
     */
    public static function tags(string $source): array
    {
        return self::build($source, static fn (MarkupToken $token): bool => true);
    }

    public static function text(string $source): string
    {
        return MarkupLexer::text($source);
    }

    /**
     * @param  callable(MarkupToken): bool  $wanted
     * @return list<MarkupElement>
     */
    private static function build(string $source, callable $wanted): array
    {
        $tokens = MarkupLexer::tokens($source);
        $elements = [];

        foreach ($tokens as $index => $token) {
            if ($token->closing || ! $wanted($token)) {
                continue;
            }

            $elements[] = new MarkupElement(
                $token->name,
                $token->start,
                substr($source, $token->start, $token->end - $token->start + 1),
                $token->empty ? '' : self::innerOf($source, $tokens, $index),
            );
        }

        return $elements;
    }

    /**
     * @param  list<MarkupToken>  $tokens
     */
    private static function innerOf(string $source, array $tokens, int $index): ?string
    {
        $open = $tokens[$index];
        $name = strtolower($open->name);
        $depth = 0;

        foreach (array_slice($tokens, $index) as $token) {
            if (strtolower($token->name) !== $name) {
                continue;
            }

            $depth += $token->closing ? -1 : ($token->empty ? 0 : 1);

            if ($depth === 0) {
                return substr($source, $open->end + 1, $token->start - $open->end - 1);
            }
        }

        return null;
    }
}
