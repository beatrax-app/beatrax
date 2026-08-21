<?php

declare(strict_types=1);

use Tests\Contracts\Support\BackendSourceFiles;

/**
 * A class that both uses a trait and declares a method of the same name wins
 * silently — PHP prefers the class, no error, no warning. AnomalyAlertDtoMapper
 * used CoercesScalars and redeclared toStringOrNull() with the opposite answer
 * for '', so deleting the "duplicate" would have flipped null to '' with
 * nothing to catch it. The rule is the fix: name the difference, or share it.
 *
 * @return list<string> one entry per class method shadowing a trait's
 */
function traitShadowViolations(array $paths): array
{
    $hits = [];

    foreach ($paths as $path) {
        foreach (traitShadowDeclarations($path) as $declaration) {
            foreach ($declaration['traits'] as $trait) {
                // First-party traits only. A framework trait's same-named
                // method is usually its documented override hook — Eloquent's
                // HasFactory::newFactory() is exactly that — and we do not own
                // the semantics a vendor trait promises either way.
                if (! str_starts_with($trait, 'Modules\\') && ! str_starts_with($trait, 'App\\')) {
                    continue;
                }

                foreach (traitShadowMethodNames($trait) as $method) {
                    if (! array_key_exists($method, $declaration['methods'])) {
                        continue;
                    }

                    $hits[] = "{$path}:{$declaration['methods'][$method]} {$declaration['name']} redeclares {$trait}::{$method}()";
                }
            }
        }
    }

    return $hits;
}

/**
 * @return list<string> the trait's own method names, empty when it does not resolve
 */
function traitShadowMethodNames(string $trait): array
{
    if (! trait_exists($trait)) {
        return [];
    }

    $names = [];
    foreach ((new ReflectionClass($trait))->getMethods() as $method) {
        $names[] = $method->getName();
    }

    return $names;
}

/**
 * Every class-like declaration in the file that uses at least one trait, with
 * the methods it declares itself.
 *
 * @return list<array{name:string,traits:list<string>,methods:array<string,int>}>
 */
function traitShadowDeclarations(string $path): array
{
    $tokens = BackendSourceFiles::codeTokens($path);
    $namespace = traitShadowNamespace($tokens);
    $imports = traitShadowImports($tokens);

    $found = [];
    $depth = 0;
    $current = null;
    $pending = null;

    foreach ($tokens as $index => $token) {
        if (is_string($token)) {
            if ($token === '{') {
                $depth++;
                if ($pending !== null) {
                    $current = ['name' => $pending, 'depth' => $depth, 'traits' => [], 'methods' => []];
                    $pending = null;
                }
            } elseif ($token === '}') {
                if ($current !== null && $current['depth'] === $depth) {
                    $found[] = $current;
                    $current = null;
                }
                $depth--;
            }

            continue;
        }

        // "{$x}" opens a brace as an array token but closes as a plain '}',
        // so an uncounted one walks the body depth off by one for the rest
        // of the file — which is how the first draft of this guard read zero
        // methods on every class with an interpolated string in it.
        if ($token[0] === T_CURLY_OPEN || $token[0] === T_DOLLAR_OPEN_CURLY_BRACES) {
            $depth++;

            continue;
        }

        if (in_array($token[0], [T_CLASS, T_ENUM], true)) {
            $name = traitShadowDeclaredName($tokens, $index);
            if ($name !== null) {
                $pending = $namespace === '' ? $name : $namespace.'\\'.$name;
            }

            continue;
        }

        if ($current === null || $depth !== $current['depth']) {
            continue;
        }

        if ($token[0] === T_USE) {
            foreach (traitShadowUsedNames($tokens, $index) as $short) {
                $current['traits'][] = traitShadowResolve($short, $namespace, $imports);
            }

            continue;
        }

        if ($token[0] === T_FUNCTION) {
            $method = traitShadowDeclaredName($tokens, $index);
            if ($method !== null) {
                $current['methods'][$method] = $token[2];
            }
        }
    }

    return array_values(array_filter($found, static fn (array $d): bool => $d['traits'] !== []));
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function traitShadowDeclaredName(array $tokens, int $index): ?string
{
    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        if (is_string($tokens[$i])) {
            return null;
        }
        if ($tokens[$i][0] === T_STRING) {
            return $tokens[$i][1];
        }
        if ($tokens[$i][0] !== T_WHITESPACE) {
            return null;
        }
    }

    return null;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return list<string>
 */
function traitShadowUsedNames(array $tokens, int $index): array
{
    $names = [];
    $buffer = '';

    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        if (is_string($tokens[$i])) {
            if ($tokens[$i] === ',') {
                if ($buffer !== '') {
                    $names[] = $buffer;
                    $buffer = '';
                }

                continue;
            }
            if ($tokens[$i] === ';' || $tokens[$i] === '{') {
                break;
            }

            continue;
        }

        if (in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            $buffer .= $tokens[$i][1];
        }
    }

    if ($buffer !== '') {
        $names[] = $buffer;
    }

    return $names;
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function traitShadowNamespace(array $tokens): string
{
    foreach ($tokens as $index => $token) {
        if (! is_array($token) || $token[0] !== T_NAMESPACE) {
            continue;
        }

        for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
            if (is_string($tokens[$i])) {
                break;
            }
            if (in_array($tokens[$i][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                return $tokens[$i][1];
            }
        }
    }

    return '';
}

/**
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 * @return array<string, string> short name (or alias) => fully qualified
 */
function traitShadowImports(array $tokens): array
{
    $imports = [];
    $depth = 0;

    foreach ($tokens as $index => $token) {
        if (is_string($token)) {
            $depth += $token === '{' ? 1 : ($token === '}' ? -1 : 0);

            continue;
        }
        if ($token[0] !== T_USE || $depth > 0) {
            continue;
        }

        $names = traitShadowUsedNames($tokens, $index);
        $alias = null;
        foreach ($tokens as $i => $candidate) {
            if ($i > $index && is_array($candidate) && $candidate[0] === T_AS) {
                $alias = traitShadowDeclaredName($tokens, $i);

                break;
            }
        }

        foreach ($names as $name) {
            $parts = explode('\\', $name);
            $imports[$alias ?? end($parts)] = $name;
        }
    }

    return $imports;
}

/**
 * @param  array<string, string>  $imports
 */
function traitShadowResolve(string $short, string $namespace, array $imports): string
{
    $short = ltrim($short, '\\');
    $head = explode('\\', $short)[0];

    if (array_key_exists($head, $imports)) {
        return $imports[$head].substr($short, strlen($head));
    }

    if (str_contains($short, '\\')) {
        return $short;
    }

    return $namespace === '' ? $short : $namespace.'\\'.$short;
}

it('never lets a class quietly redeclare a method its trait already provides', function (): void {
    expect(traitShadowViolations(BackendSourceFiles::all()))->toBe([]);
});

it('sees a planted shadow, including through an interpolated string', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'trait-shadow').'.php';
    file_put_contents($planted, <<<'PHP'
        <?php
        namespace Planted;
        use Modules\Core\Public\Concerns\CoercesScalars;
        final class PlantedTraitShadow
        {
            use CoercesScalars;

            public function label(mixed $value): string
            {
                return "value: {$value}";
            }

            private static function toString(mixed $value): string
            {
                return 'shadowed';
            }
        }
        PHP);

    try {
        $found = traitShadowViolations([$planted]);
    } finally {
        @unlink($planted);
    }

    expect($found)->toHaveCount(1);
    expect($found[0])->toContain('redeclares Modules\Core\Public\Concerns\CoercesScalars::toString()');
});
