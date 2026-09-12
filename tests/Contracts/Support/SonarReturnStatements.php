<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

/**
 * @link ../../../.docs/conventions/analyser-rules-enforced-locally.md#s1142--too-many-return-statements
 */
final class SonarReturnStatements
{
    /**
     * Every declaration with a body, carrying the `return` statements written
     * in ITS body and in no nested one.
     *
     * @return list<array{name:string,line:int,nameIndex:int,open:int,close:int,returns:int}>
     */
    public static function functions(string $source): array
    {
        $tokens = SonarSourceFiles::tokens($source);

        return self::read($tokens, SonarSourceFiles::brackets($tokens));
    }

    /**
     * An arrow function is deliberately absent from the answer. Its body is an
     * expression, so it can hold no `return` statement of its own and can
     * never be a finding; a closure written inside one has a braced body and
     * is read on its own terms, one level deeper.
     *
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @return list<array{name:string,line:int,nameIndex:int,open:int,close:int,returns:int}>
     */
    public static function read(array $tokens, array $brackets): array
    {
        $functions = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = ($tokens[$i + 1][0] ?? null) === null && ($tokens[$i + 1][1] ?? '') === '&'
                ? $i + 2
                : $i + 1;

            $body = self::body($tokens, $brackets, $nameIndex);

            // An abstract or interface method, and `use function Foo\bar;`,
            // all end at a semicolon. There is no body to count in.
            if ($body === null) {
                continue;
            }

            $functions[] = [
                'name' => ($tokens[$nameIndex][0] ?? null) === T_STRING ? $tokens[$nameIndex][1] : '{closure}',
                'line' => $tokens[$i][2],
                'nameIndex' => $nameIndex,
                'open' => $body[0],
                'close' => $body[1],
                'returns' => 0,
            ];
        }

        foreach ($tokens as $index => $token) {
            if ($token[0] !== T_RETURN) {
                continue;
            }

            $owner = self::innermost($functions, $index);

            if ($owner !== null) {
                $functions[$owner]['returns']++;
            }
        }

        return $functions;
    }

    /**
     * The `return` belongs to the closest body around it and to no other, so
     * a closure passed to a collection method charges its own count and not
     * the method that wrote it. Bodies nest properly, so the innermost is the
     * one that opens last.
     *
     * @param  list<array{name:string,line:int,nameIndex:int,open:int,close:int,returns:int}>  $functions
     */
    private static function innermost(array $functions, int $index): ?int
    {
        $owner = null;

        foreach ($functions as $position => $function) {
            if ($index <= $function['open'] || $index >= $function['close']) {
                continue;
            }

            if ($owner === null || $function['open'] > $functions[$owner]['open']) {
                $owner = $position;
            }
        }

        return $owner;
    }

    /**
     * The brace pair holding a declaration's statements, or null where it has
     * none. Parenthesised runs are stepped over whole: the parameter list, a
     * closure's `use`, and a DNF return type all hold their own brackets, and
     * none of them holds the brace this is looking for.
     *
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @return array{0:int,1:int}|null
     */
    private static function body(array $tokens, array $brackets, int $nameIndex): ?array
    {
        $count = count($tokens);

        for ($j = $nameIndex; $j < $count; $j++) {
            if ($tokens[$j][0] !== null) {
                continue;
            }

            if ($tokens[$j][1] === '(') {
                $j = $brackets[$j] ?? $j;

                continue;
            }

            if ($tokens[$j][1] === '{') {
                return [$j, $brackets[$j] ?? $count - 1];
            }

            if ($tokens[$j][1] === ';') {
                return null;
            }
        }

        return null;
    }
}
