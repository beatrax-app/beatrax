<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

// The three money guards ask the same two questions of a file — "which function
// is this token inside" and "which columns does this array literal name" — and
// each answering them itself is the duplication those guards exist to stop.
final class MoneySourceShape
{
    /**
     * Every declared function, outermost first, as its name, its token bounds
     * and its source text. A closure is named for the enclosing declaration it
     * was written inside, because that is the code a reader would go and read.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens  as BackendSourceFiles::codeTokens() returns
     * @return list<array{name: string, from: int, to: int, body: string}>
     */
    public static function functions(array $tokens): array
    {
        $texts = self::texts($tokens);
        $count = count($tokens);
        $functions = [];

        for ($i = 0; $i < $count; $i++) {
            if (! is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $open = self::openingBrace($texts, $i);
            if ($open === null) {
                continue;
            }

            $close = self::closingBrace($texts, $open);
            $functions[] = [
                'name' => self::functionName($tokens, $texts, $i),
                'from' => $i,
                'to' => $close,
                'body' => implode('', array_slice($texts, $i, $close - $i + 1)),
            ];
        }

        return $functions;
    }

    /**
     * The innermost function a token index sits inside, or null when it sits
     * outside every one of them.
     *
     * @param  list<array{name: string, from: int, to: int, body: string}>  $functions  as functions() returns
     * @return ?array{name: string, from: int, to: int, body: string}
     */
    public static function enclosing(array $functions, int $index): ?array
    {
        $found = null;

        foreach ($functions as $function) {
            if ($index > $function['from'] && $index < $function['to']) {
                $found = $function;
            }
        }

        return $found;
    }

    /**
     * The nearest enclosing function a reader could name, skipping past the
     * closures a query builder is full of.
     *
     * @param  list<array{name: string, from: int, to: int, body: string}>  $functions  as functions() returns
     */
    public static function enclosingName(array $functions, int $index): string
    {
        $name = 'file';

        foreach ($functions as $function) {
            if ($index > $function['from'] && $index < $function['to'] && $function['name'] !== 'closure') {
                $name = $function['name'];
            }
        }

        return $name;
    }

    /**
     * Every array literal in the file, as line number => the names it uses as
     * KEYS. A bare element contributes nothing: only `'name' => value` is a
     * column being written.
     *
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return array<int, list<string>>
     */
    public static function keyedArrayLiterals(array $tokens): array
    {
        $texts = self::texts($tokens);
        $count = count($tokens);

        $open = [];
        $nextId = 0;
        $keys = [];
        $lines = [];

        for ($i = 0; $i < $count; $i++) {
            if ($texts[$i] === '[') {
                $open[] = $nextId++;

                continue;
            }
            if ($texts[$i] === ']') {
                array_pop($open);

                continue;
            }
            if ($open === [] || ! is_array($tokens[$i]) || $tokens[$i][0] !== T_CONSTANT_ENCAPSED_STRING) {
                continue;
            }
            if (self::nextMeaningful($texts, $i) !== '=>') {
                continue;
            }

            $id = $open[count($open) - 1];
            $keys[$id][] = trim($texts[$i], "'\"");
            $lines[$id] ??= $tokens[$i][2];
        }

        $byLine = [];
        foreach ($keys as $id => $names) {
            $byLine[$lines[$id]] = array_values(array_unique($names));
        }

        return $byLine;
    }

    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @return list<string>
     */
    private static function texts(array $tokens): array
    {
        return array_map(
            static fn (array|string $token): string => is_array($token) ? $token[1] : $token,
            $tokens,
        );
    }

    /**
     * @param  list<string>  $texts
     */
    private static function openingBrace(array $texts, int $from): ?int
    {
        $depth = 0;

        for ($i = $from + 1, $count = count($texts); $i < $count; $i++) {
            $text = $texts[$i];

            if ($text === '(') {
                $depth++;
            } elseif ($text === ')') {
                $depth--;
            } elseif ($text === '{' && $depth === 0) {
                return $i;
            } elseif ($text === ';' && $depth === 0) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $texts
     */
    private static function closingBrace(array $texts, int $open): int
    {
        $depth = 0;

        for ($i = $open, $count = count($texts); $i < $count; $i++) {
            if ($texts[$i] === '{') {
                $depth++;
            } elseif ($texts[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return $i;
                }
            }
        }

        return $count - 1;
    }

    // Matched on the spelling rather than on T_STRING: a method may be named
    // for a reserved word, and `public function for()` tokenises as T_FOR —
    // read as a closure, it took its whole class out of every walk here.
    /**
     * @param  list<array{0:int,1:string,2:int}|string>  $tokens
     * @param  list<string>  $texts
     */
    private static function functionName(array $tokens, array $texts, int $index): string
    {
        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if ($texts[$i] === '(') {
                return 'closure';
            }
            if (is_array($tokens[$i]) && preg_match('/^[A-Za-z_]\w*$/', $texts[$i]) === 1) {
                return $texts[$i];
            }
        }

        return 'closure';
    }

    /**
     * @param  list<string>  $texts
     */
    private static function nextMeaningful(array $texts, int $index): string
    {
        for ($i = $index + 1, $count = count($texts); $i < $count; $i++) {
            if (trim($texts[$i]) !== '') {
                return $texts[$i];
            }
        }

        return '';
    }
}
