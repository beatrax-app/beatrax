<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#a-log-line-a-peer-can-repeat
 */
final class LoopBoundLogLines
{
    private const array LEVELS = [
        'emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug',
    ];

    // Control leaving the loop is the bound. `continue` is deliberately absent:
    // it is the shape both floods had, and it guarantees the next iteration.
    private const array LEAVES = [T_BREAK, T_RETURN, T_THROW, T_EXIT];

    private const array LOOPS = [T_FOREACH, T_FOR, T_WHILE, T_DO];

    private const array ARROWS = [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON];

    /**
     * @return array{calls:int,offenders:list<array{line:int,name:string}>}
     */
    public static function scan(string $source): array
    {
        $tokens = SonarSourceFiles::tokens($source);
        $brackets = SonarSourceFiles::brackets($tokens);
        $loops = self::loopBodies($tokens, $brackets);

        $calls = 0;
        $offenders = [];

        foreach (self::logCalls($tokens) as $index) {
            $calls++;

            if (self::insideLoop($index, $loops) && ! self::leavesTheLoop($index, $tokens, $brackets)) {
                $offenders[] = ['line' => $tokens[$index][2], 'name' => $tokens[$index][1]];
            }
        }

        return ['calls' => $calls, 'offenders' => $offenders];
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @return list<array{0:int,1:int}>
     */
    private static function loopBodies(array $tokens, array $brackets): array
    {
        $bodies = [];

        foreach ($tokens as $index => $token) {
            if (! in_array($token[0], self::LOOPS, true)) {
                continue;
            }

            $open = self::bodyBrace($index, $tokens, $brackets);

            if ($open !== null) {
                $bodies[] = [$open, $brackets[$open]];
            }
        }

        return $bodies;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     */
    private static function bodyBrace(int $index, array $tokens, array $brackets): ?int
    {
        $next = $index + 1;

        // A `do` block's body opens immediately; every other loop opens after
        // its header. The trailing `while (…)` of a do-block therefore finds a
        // `;` here and is skipped, which is right — its body is already read.
        if ($tokens[$index][0] !== T_DO) {
            if (($tokens[$next][1] ?? '') !== '(' || ! isset($brackets[$next])) {
                return null;
            }

            $next = $brackets[$next] + 1;
        }

        return ($tokens[$next][1] ?? '') === '{' && isset($brackets[$next]) ? $next : null;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @return list<int>
     */
    private static function logCalls(array $tokens): array
    {
        $found = [];

        foreach (array_keys($tokens) as $index) {
            if (self::isLogCall($index, $tokens)) {
                $found[] = $index;
            }
        }

        return $found;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    private static function isLogCall(int $index, array $tokens): bool
    {
        return $tokens[$index][0] === T_STRING
            && in_array(strtolower($tokens[$index][1]), self::LEVELS, true)
            && in_array($tokens[$index - 1][0] ?? null, self::ARROWS, true)
            && ($tokens[$index + 1][1] ?? '') === '('
            && stripos($tokens[$index - 2][1] ?? '', 'log') !== false;
    }

    /**
     * @param  list<array{0:int,1:int}>  $loops
     */
    private static function insideLoop(int $index, array $loops): bool
    {
        foreach ($loops as [$open, $close]) {
            if ($index > $open && $index < $close) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     */
    private static function leavesTheLoop(int $index, array $tokens, array $brackets): bool
    {
        $at = isset($brackets[$index + 1]) ? $brackets[$index + 1] + 1 : $index + 1;
        $count = count($tokens);

        // Only this statement's own block is read. A `break` further out runs on
        // every iteration anyway, so it bounds the loop rather than the line,
        // and reading it as a bound would admit the flood beside it.
        while ($at < $count && $tokens[$at][1] !== '}') {
            if (in_array($tokens[$at][0], self::LEAVES, true)) {
                return true;
            }

            $at = in_array($tokens[$at][1], ['{', '(', '['], true) && isset($brackets[$at])
                ? $brackets[$at] + 1
                : $at + 1;
        }

        return false;
    }
}
