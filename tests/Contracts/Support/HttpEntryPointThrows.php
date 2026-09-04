<?php

declare(strict_types=1);

namespace Tests\Contracts\Support;

use Illuminate\Routing\Router;
use ReflectionClass;

/**
 * @link ../../../.docs/conventions/invariants-from-shipped-failures.md#an-expected-condition-answering-as-a-server-fault
 */
final class HttpEntryPointThrows
{
    /**
     * A component mounted by a layout has no route of its own and is still
     * reachable from an update payload, so the router's own list is only half
     * the boundary; every file under an `Http/` directory is the other half.
     *
     * @return list<string>
     */
    public static function files(Router $router): array
    {
        $files = array_values(array_filter(
            SonarSourceFiles::all(),
            static fn (string $path): bool => str_contains($path, '/Http/'),
        ));

        foreach (self::routedClasses($router) as $class) {
            $file = (new ReflectionClass($class))->getFileName();

            if (is_string($file)) {
                $files[] = $file;
            }
        }

        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    /**
     * @return list<class-string>
     */
    public static function routedClasses(Router $router): array
    {
        $classes = [];

        foreach ($router->getRoutes() as $route) {
            $uses = $route->getAction()['uses'] ?? null;

            if (is_string($uses)) {
                self::remember($classes, explode('@', $uses)[0]);
            }

            foreach ($route->gatherMiddleware() as $middleware) {
                if (is_string($middleware)) {
                    self::remember($classes, explode(':', $middleware)[0]);
                }
            }
        }

        $named = array_keys($classes);
        sort($named);

        /** @var list<class-string> $named */
        return $named;
    }

    /**
     * Every `throw` in a file that no `catch` in the same file covers.
     *
     * @return list<array{class:string,line:int}>
     */
    public static function unguarded(string $source): array
    {
        $tokens = SonarSourceFiles::tokens($source);
        $brackets = SonarSourceFiles::brackets($tokens);
        $imports = self::imports($tokens);
        $methods = self::methodRanges($tokens, $brackets);
        $guards = self::tryRegions($tokens, $brackets, $imports, $methods);

        $unguarded = [];

        foreach (self::throwSites($tokens, $imports) as $site) {
            if (! self::covered($site, $guards, self::enclosingMethod($site['index'], $methods))) {
                $unguarded[] = ['class' => $site['class'], 'line' => $site['line']];
            }
        }

        return $unguarded;
    }

    /**
     * Lexically inside a covering try, or inside a method the covered code
     * reaches: a coercion helper raising for its own caller is guarded by the
     * try around the call, several frames above the `throw`.
     *
     * @param  array{class:string,line:int,index:int}  $site
     * @param  list<array{open:int,close:int,caught:list<string>,reaches:list<string>}>  $guards
     */
    private static function covered(array $site, array $guards, ?string $enclosing): bool
    {
        foreach ($guards as $guard) {
            $inside = $site['index'] >= $guard['open'] && $site['index'] <= $guard['close'];
            $reached = $enclosing !== null && in_array($enclosing, $guard['reaches'], true);

            if (! $inside && ! $reached) {
                continue;
            }

            foreach ($guard['caught'] as $caught) {
                if ($caught === $site['class'] || self::isSubtype($site['class'], $caught)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param  array<string,array{open:int,close:int}>  $methods
     */
    private static function enclosingMethod(int $index, array $methods): ?string
    {
        foreach ($methods as $name => $range) {
            if ($index > $range['open'] && $index < $range['close']) {
                return $name;
            }
        }

        return null;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @return array<string,array{open:int,close:int}>
     */
    private static function methodRanges(array $tokens, array $brackets): array
    {
        $ranges = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_FUNCTION || ($tokens[$i + 1][0] ?? null) !== T_STRING) {
                continue;
            }

            $name = $tokens[$i + 1][1];

            for ($j = $i + 2; $j < $count; $j++) {
                if ($tokens[$j][1] === ';') {
                    break;
                }
                if ($tokens[$j][1] === '(') {
                    $j = $brackets[$j] ?? $j;

                    continue;
                }
                if ($tokens[$j][1] === '{') {
                    $ranges[$name] = ['open' => $j, 'close' => $brackets[$j] ?? $j];

                    break;
                }
            }
        }

        return $ranges;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<string,array{open:int,close:int}>  $methods
     * @return list<string>
     */
    private static function reachableMethods(array $tokens, array $methods, int $open, int $close): array
    {
        $reached = self::calledWithin($tokens, $open, $close, $methods);
        $pending = $reached;

        while ($pending !== []) {
            $name = array_pop($pending);
            $range = $methods[$name] ?? null;

            if ($range === null) {
                continue;
            }

            foreach (self::calledWithin($tokens, $range['open'], $range['close'], $methods) as $next) {
                if (! in_array($next, $reached, true)) {
                    $reached[] = $next;
                    $pending[] = $next;
                }
            }
        }

        return $reached;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<string,array{open:int,close:int}>  $methods
     * @return list<string>
     */
    private static function calledWithin(array $tokens, int $open, int $close, array $methods): array
    {
        $called = [];

        for ($i = $open; $i < $close; $i++) {
            if (! in_array($tokens[$i][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON], true)) {
                continue;
            }

            $name = $tokens[$i + 1][1] ?? '';

            if (isset($methods[$name]) && ! in_array($name, $called, true)) {
                $called[] = $name;
            }
        }

        return $called;
    }

    private static function isSubtype(string $thrown, string $caught): bool
    {
        if (! class_exists($thrown)) {
            return false;
        }

        return (class_exists($caught) || interface_exists($caught)) && is_a($thrown, $caught, true);
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array{namespace:string,aliases:array<string,string>}  $imports
     * @return list<array{class:string,line:int,index:int}>
     */
    private static function throwSites(array $tokens, array $imports): array
    {
        $sites = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_THROW) {
                continue;
            }

            $next = $i + 1;
            $isNew = $next < $count && $tokens[$next][0] === T_NEW;
            $name = self::readName($tokens, $isNew ? $next + 1 : $next);

            if ($name === null) {
                continue;
            }

            // A rethrow (`throw $e`) names no class, and a static factory only
            // names one when the very next token is the `::` that calls it.
            $after = ($isNew ? $next + 1 : $next) + $name['length'];
            if (! $isNew && ($after >= $count || $tokens[$after][0] !== T_DOUBLE_COLON)) {
                continue;
            }

            $sites[] = [
                'class' => self::resolve($name['text'], $imports),
                'line' => $tokens[$i][2],
                'index' => $i,
            ];
        }

        return $sites;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @param  array{namespace:string,aliases:array<string,string>}  $imports
     * @param  array<string,array{open:int,close:int}>  $methods
     * @return list<array{open:int,close:int,caught:list<string>,reaches:list<string>}>
     */
    private static function tryRegions(array $tokens, array $brackets, array $imports, array $methods): array
    {
        $regions = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            if ($tokens[$i][0] !== T_TRY) {
                continue;
            }

            $open = $i + 1;
            if ($open >= $count || $tokens[$open][1] !== '{') {
                continue;
            }

            $close = $brackets[$open] ?? $open;
            $regions[] = [
                'open' => $open,
                'close' => $close,
                'caught' => self::caughtTypes($tokens, $brackets, $close, $imports),
                'reaches' => self::reachableMethods($tokens, $methods, $open, $close),
            ];
        }

        return $regions;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @param  array<int,int>  $brackets
     * @param  array{namespace:string,aliases:array<string,string>}  $imports
     * @return list<string>
     */
    private static function caughtTypes(array $tokens, array $brackets, int $close, array $imports): array
    {
        $caught = [];
        $count = count($tokens);
        $i = $close + 1;

        while ($i < $count && $tokens[$i][0] === T_CATCH) {
            $paren = $i + 1;
            $end = $brackets[$paren] ?? $paren;

            for ($j = $paren + 1; $j < $end; $j++) {
                $name = self::readName($tokens, $j);

                if ($name !== null) {
                    $caught[] = self::resolve($name['text'], $imports);
                    $j += $name['length'] - 1;
                }
            }

            $body = $end + 1;
            $i = ($brackets[$body] ?? $body) + 1;
        }

        return $caught;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @return array{text:string,length:int}|null
     */
    private static function readName(array $tokens, int $from): ?array
    {
        $names = [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_NAME_RELATIVE, T_NS_SEPARATOR];
        $text = '';
        $length = 0;
        $count = count($tokens);

        for ($i = $from; $i < $count && in_array($tokens[$i][0], $names, true); $i++) {
            $text .= $tokens[$i][1];
            $length++;
        }

        return $text === '' ? null : ['text' => $text, 'length' => $length];
    }

    /**
     * @param  array{namespace:string,aliases:array<string,string>}  $imports
     */
    private static function resolve(string $name, array $imports): string
    {
        if (str_starts_with($name, '\\')) {
            return ltrim($name, '\\');
        }

        $head = explode('\\', $name)[0];

        if (isset($imports['aliases'][$head])) {
            return $imports['aliases'][$head].substr($name, strlen($head));
        }

        if (class_exists($name) || interface_exists($name)) {
            return $name;
        }

        return $imports['namespace'] === '' ? $name : $imports['namespace'].'\\'.$name;
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @return array{namespace:string,aliases:array<string,string>}
     */
    private static function imports(array $tokens): array
    {
        $namespace = '';
        $aliases = [];
        $count = count($tokens);

        for ($i = 0; $i < $count; $i++) {
            // `use` also opens a trait import and a closure's binding list, so
            // reading stops at the first type declaration: past it, the word
            // never introduces a class alias again.
            if (in_array($tokens[$i][0], [T_CLASS, T_INTERFACE, T_TRAIT, T_ENUM], true)) {
                break;
            }

            if ($tokens[$i][0] === T_NAMESPACE) {
                $name = self::readName($tokens, $i + 1);
                $namespace = $name === null ? '' : trim($name['text'], '\\');

                continue;
            }

            if ($tokens[$i][0] === T_USE) {
                $alias = self::readAlias($tokens, $i + 1);

                if ($alias !== null) {
                    $aliases[$alias[0]] = $alias[1];
                }
            }
        }

        return ['namespace' => $namespace, 'aliases' => $aliases];
    }

    /**
     * @param  list<array{0:int|null,1:string,2:int}>  $tokens
     * @return array{0:string,1:string}|null
     */
    private static function readAlias(array $tokens, int $from): ?array
    {
        if (in_array($tokens[$from][0] ?? null, [T_FUNCTION, T_CONST], true)) {
            return null;
        }

        $name = self::readName($tokens, $from);

        if ($name === null) {
            return null;
        }

        $fqcn = trim($name['text'], '\\');
        $after = $from + $name['length'];

        if (($tokens[$after][0] ?? null) === T_AS) {
            $renamed = self::readName($tokens, $after + 1);

            return $renamed === null ? null : [$renamed['text'], $fqcn];
        }

        // A grouped or listed import leaves more than one name behind the same
        // statement, and reading only the first would resolve the rest wrong.
        if (($tokens[$after][1] ?? '') !== ';') {
            return null;
        }

        $short = strrpos($fqcn, '\\');

        return [$short === false ? $fqcn : substr($fqcn, $short + 1), $fqcn];
    }

    /**
     * @param  array<string,bool>  $classes
     */
    private static function remember(array &$classes, string $candidate): void
    {
        if (str_starts_with($candidate, 'Modules\\') || str_starts_with($candidate, 'App\\')) {
            if (class_exists($candidate)) {
                $classes[$candidate] = true;
            }
        }
    }
}
