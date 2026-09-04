<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

/**
 * @link ../../../.docs/conventions/a-controller-hands-the-work-to-an-action.md
 */
final class ControllerShape
{
    public const int MAX_METHODS = 7;

    public const int MAX_STATEMENTS = 12;

    public const int MAX_COMPLEXITY = 6;

    /**
     * Naming one of these is how a controller stops being a controller: a
     * connection, a query builder or an Eloquent model in scope is one
     * `->update()` away from making the HTTP layer a writer.
     *
     * @var list<string>
     */
    private const PERSISTENCE_NAMES = [
        'Illuminate\\Database\\',
        'Illuminate\\Contracts\\Database\\',
        'Illuminate\\Support\\Facades\\DB',
    ];

    private const MODEL_PATTERN = '/\\bModules\\\\[A-Za-z0-9_]+\\\\Models\\\\/';

    /**
     * A file declaring an HTTP entry point, by either spelling this tree uses:
     * a `Http/Controllers/` directory, or a class named for what it is.
     *
     * @return list<string>
     */
    public static function files(): array
    {
        return array_values(array_filter(
            SonarSourceFiles::all(),
            static fn (string $path): bool => str_contains($path, '/Http/Controllers/')
                || str_ends_with($path, 'Controller.php'),
        ));
    }

    /**
     * @return list<string> one sentence per rule this source breaks
     */
    public static function offences(string $source): array
    {
        $tokens = SonarSourceFiles::tokens($source);
        $brackets = SonarSourceFiles::brackets($tokens);
        $complexity = self::complexityByFunction($source);

        $offences = self::persistenceReach($tokens);

        foreach (SonarClassShape::types($tokens, $brackets) as $type) {
            if ($type['kind'] !== 'class') {
                continue;
            }

            $methods = array_values(array_filter(
                SonarClassShape::methods($tokens, $brackets, $type['open'], $type['close']),
                static fn (array $method): bool => $method['name'] !== '__construct',
            ));

            if (count($methods) > self::MAX_METHODS) {
                $offences[] = $type['name'].' declares '.count($methods)
                    .' methods besides its constructor (at most '.self::MAX_METHODS.')';
            }

            foreach ($methods as $method) {
                $offences = array_merge($offences, self::methodOffences($tokens, $brackets, $method, $complexity));
            }
        }

        return $offences;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @param  array{name:string,line:int,nameIndex:int,modifiers:list<string>}  $method
     * @param  array<string,int>  $complexity
     * @return list<string>
     */
    private static function methodOffences(array $tokens, array $brackets, array $method, array $complexity): array
    {
        $offences = [];
        $statements = self::statements($tokens, $brackets, $method['nameIndex']);

        if ($statements > self::MAX_STATEMENTS) {
            $offences[] = $method['name'].'() runs '.$statements
                .' statements (at most '.self::MAX_STATEMENTS.')';
        }

        $score = $complexity[$method['name']] ?? 0;
        if ($score > self::MAX_COMPLEXITY) {
            $offences[] = $method['name'].'() scores '.$score
                .' on cognitive complexity (at most '.self::MAX_COMPLEXITY.')';
        }

        return $offences;
    }

    /**
     * The statements a method's own body runs, closures it declares included:
     * a body handed to `new StreamedResponse(...)` is still this method's
     * work, and folding it in is what the complexity scoring already does.
     *
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     */
    public static function statements(array $tokens, array $brackets, int $nameIndex): int
    {
        $count = count($tokens);
        $open = null;

        for ($i = $nameIndex + 1; $i < $count; $i++) {
            if ($tokens[$i][0] === null && $tokens[$i][1] === '(') {
                $i = $brackets[$i] ?? $i;

                continue;
            }
            if ($tokens[$i][0] === null && $tokens[$i][1] === '{') {
                $open = $i;

                break;
            }
            if ($tokens[$i][0] === null && $tokens[$i][1] === ';') {
                return 0;
            }
        }

        if ($open === null) {
            return 0;
        }

        $close = $brackets[$open] ?? $open;
        $statements = 0;

        for ($i = $open + 1; $i < $close; $i++) {
            // The only semicolons inside parentheses are a `for` header's, and
            // those separate one statement rather than ending three.
            if ($tokens[$i][0] === T_FOR) {
                $paren = self::nextParen($tokens, $i, $close);
                $i = $paren === null ? $i : ($brackets[$paren] ?? $i);

                continue;
            }
            if ($tokens[$i][0] === null && $tokens[$i][1] === ';') {
                $statements++;
            }
        }

        return $statements;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     */
    private static function nextParen(array $tokens, int $from, int $close): ?int
    {
        for ($i = $from + 1; $i < $close; $i++) {
            if ($tokens[$i][0] === null && $tokens[$i][1] === '(') {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @return list<string>
     */
    private static function persistenceReach(array $tokens): array
    {
        $code = implode("\n", array_column($tokens, 1));
        $found = [];

        foreach (self::PERSISTENCE_NAMES as $name) {
            if (str_contains($code, $name)) {
                $found[] = 'names '.$name;
            }
        }

        if (preg_match(self::MODEL_PATTERN, $code) === 1) {
            $found[] = 'names an Eloquent model under Modules\\<X>\\Models\\';
        }

        return $found;
    }

    /**
     * @return array<string,int> the highest score recorded for each name
     */
    private static function complexityByFunction(string $source): array
    {
        $scores = [];

        foreach (SonarCognitiveComplexity::analyse($source)['functions'] as $function) {
            $scores[$function['name']] = max($scores[$function['name']] ?? 0, $function['value']);
        }

        return $scores;
    }
}
