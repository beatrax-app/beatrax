<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

/**
 * @link ../../../.docs/conventions/analyser-rules-enforced-locally.md#the-scope-every-guard-reads
 */
final class SonarClassShape
{
    /**
     * Every type declared in a file, in source order.
     *
     * `inherits` is the one thing the parameter guard turns on: a method on a
     * type that extends or implements something may be overriding a signature
     * the analyser cannot see, and the analyser stays silent when it cannot
     * rule that out.
     *
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @return list<array{kind:string,name:string,line:int,open:int,close:int,inherits:bool}>
     */
    public static function types(array $tokens, array $brackets): array
    {
        $types = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            $id = $tokens[$i][0];

            if (! in_array($id, [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                continue;
            }

            // `Foo::class` is a name, not a declaration.
            if ($id === T_CLASS && ($tokens[$i - 1][0] ?? null) === T_DOUBLE_COLON) {
                continue;
            }

            $anonymous = $id === T_CLASS && ($tokens[$i - 1][0] ?? null) === T_NEW;
            $inherits = false;
            $open = null;

            for ($j = $i + 1; $j < $count; $j++) {
                $token = $tokens[$j];

                if ($token[0] === T_EXTENDS || $token[0] === T_IMPLEMENTS) {
                    $inherits = true;

                    continue;
                }
                if ($token[0] === null && $token[1] === '(') {
                    $j = $brackets[$j] ?? $j;

                    continue;
                }
                if ($token[0] === null && $token[1] === '{') {
                    $open = $j;

                    break;
                }
                if ($token[0] === null && $token[1] === ';') {
                    break;
                }
            }

            if ($open === null) {
                continue;
            }

            $types[] = [
                'kind' => match (true) {
                    $anonymous => 'anonymous',
                    $id === T_INTERFACE => 'interface',
                    $id === T_TRAIT => 'trait',
                    $id === T_ENUM => 'enum',
                    default => 'class',
                },
                'name' => ($tokens[$i + 1][0] ?? null) === T_STRING ? $tokens[$i + 1][1] : '{anonymous class}',
                'line' => $tokens[$i][2],
                'open' => $open,
                'close' => $brackets[$open] ?? $count - 1,
                'inherits' => $inherits,
            ];

            $i = $open;
        }

        return $types;
    }

    /**
     * The methods declared directly in a type body. Inherited methods and the
     * methods a `use`d trait brings in are not members of this declaration and
     * the analyser does not count them either, which is why moving methods
     * into a trait silences the count rather than reducing it.
     *
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @return list<array{name:string,line:int,nameIndex:int,modifiers:list<string>}>
     */
    public static function methods(array $tokens, array $brackets, int $open, int $close): array
    {
        $methods = [];

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if ($token[0] === T_ATTRIBUTE || ($token[0] === null && $token[1] === '{')) {
                $i = $brackets[$i] ?? $i;

                continue;
            }

            // A nested type declares its own members; they are not this one's.
            if (in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)
                && ($tokens[$i - 1][0] ?? null) !== T_DOUBLE_COLON) {
                for ($j = $i + 1; $j < $close; $j++) {
                    if ($tokens[$j][0] === null && $tokens[$j][1] === '{') {
                        $i = $brackets[$j] ?? $i;

                        break;
                    }
                }

                continue;
            }

            if ($token[0] !== T_FUNCTION) {
                continue;
            }

            $nameIndex = ($tokens[$i + 1][0] === null && $tokens[$i + 1][1] === '&') ? $i + 2 : $i + 1;

            // A closure parked in a property default is not a method.
            if ($tokens[$nameIndex][0] === null && $tokens[$nameIndex][1] === '(') {
                continue;
            }

            $modifiers = [];

            for ($p = $i - 1; $p > $open; $p--) {
                if (in_array($tokens[$p][0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL, T_READONLY], true)) {
                    $modifiers[] = strtolower($tokens[$p][1]);

                    continue;
                }
                if ($tokens[$p][0] === null && $tokens[$p][1] === ']') {
                    $p = ($brackets[$p] ?? $p);

                    continue;
                }

                break;
            }

            $methods[] = [
                'name' => $tokens[$nameIndex][1],
                'line' => $tokens[$i][2],
                'nameIndex' => $nameIndex,
                'modifiers' => $modifiers,
            ];

            $i = self::pastDeclaration($tokens, $brackets, $nameIndex, $close);
        }

        return $methods;
    }

    /**
     * The parameters the analyser counts. A promoted constructor parameter is
     * a field declaration wearing a parameter's syntax, and it is excluded —
     * which is why a fourteen-argument data class reports nothing.
     *
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     */
    public static function countedParameters(array $tokens, array $brackets, int $nameIndex): int
    {
        $count = count($tokens);
        $open = $nameIndex + 1;

        while ($open < $count && ! ($tokens[$open][0] === null && $tokens[$open][1] === '(')) {
            $open++;
        }

        if ($open >= $count) {
            return 0;
        }

        $close = $brackets[$open] ?? $open;
        $counted = 0;
        $promoted = false;

        for ($i = $open + 1; $i < $close; $i++) {
            $token = $tokens[$i];

            if ($token[0] === T_ATTRIBUTE || ($token[0] === null && ($token[1] === '(' || $token[1] === '['))) {
                $i = $brackets[$i] ?? $i;

                continue;
            }
            if ($token[0] === null && $token[1] === ',') {
                $promoted = false;

                continue;
            }
            if (in_array($token[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE], true)) {
                $promoted = true;

                continue;
            }
            if ($token[0] === T_VARIABLE && ! $promoted) {
                $counted++;
            }
        }

        return $counted;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     */
    private static function pastDeclaration(array $tokens, array $brackets, int $nameIndex, int $close): int
    {
        for ($j = $nameIndex + 1; $j < $close; $j++) {
            if ($tokens[$j][0] === null && $tokens[$j][1] === '(') {
                $j = $brackets[$j] ?? $j;

                continue;
            }
            if ($tokens[$j][0] === null && $tokens[$j][1] === '{') {
                return $brackets[$j] ?? $j;
            }
            if ($tokens[$j][0] === null && $tokens[$j][1] === ';') {
                return $j;
            }
        }

        return $close;
    }
}
