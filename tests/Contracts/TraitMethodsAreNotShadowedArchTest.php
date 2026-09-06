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
 * @param  list<string>  $paths
 * @return array{using: int, unresolved: list<string>, hits: list<string>} the
 *                                                                        class-likes reached that use a first-party trait, the first-party traits
 *                                                                        the autoloader could not reach, and every shadowed method found
 */
function traitShadowScan(array $paths): array
{
    $using = 0;
    $unresolved = [];
    $hits = [];

    foreach ($paths as $path) {
        foreach (traitShadowDeclarations($path) as $declaration) {
            $firstParty = false;

            foreach ($declaration['traits'] as $trait) {
                // First-party traits only. A framework trait's same-named
                // method is usually its documented override hook — Eloquent's
                // HasFactory::newFactory() is exactly that — and we do not own
                // the semantics a vendor trait promises either way.
                if (! str_starts_with($trait, 'Modules\\') && ! str_starts_with($trait, 'App\\')) {
                    continue;
                }

                $firstParty = true;

                // A trait the autoloader cannot reach yields no method names,
                // so the class using it is waved through in silence. Named
                // rather than skipped, because "no shadow found" and "nothing
                // to compare against" are the same empty answer otherwise.
                if (! trait_exists($trait)) {
                    $unresolved[] = "{$path} uses {$trait}, which does not resolve";

                    continue;
                }

                foreach (traitShadowMethodNames($trait) as $method) {
                    if (! array_key_exists($method, $declaration['methods'])) {
                        continue;
                    }

                    $hits[] = "{$path}:{$declaration['methods'][$method]} {$declaration['name']} redeclares {$trait}::{$method}()";
                }
            }

            if ($firstParty) {
                $using++;
            }
        }
    }

    return ['using' => $using, 'unresolved' => array_values(array_unique($unresolved)), 'hits' => $hits];
}

/**
 * @param  list<string>  $paths
 * @return list<string> one entry per class method shadowing a trait's
 */
function traitShadowViolations(array $paths): array
{
    return traitShadowScan($paths)['hits'];
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
 * The alias this one `use` statement declares, read no further than its own
 * semicolon. Scanned across the whole file instead, the first `as` in it keyed
 * EVERY import under that one alias — so in the 242 production files carrying
 * an aliased import, no short name resolved, every first-party trait read as
 * `<current namespace>\<short name>`, `trait_exists` said no and the class was
 * waved through. `ActualSqliteReader` uses CoercesScalars, which is the trait
 * the shipped defect this guard is named for was found in.
 *
 * @param  list<array{0:int,1:string,2:int}|string>  $tokens
 */
function traitShadowAliasIn(array $tokens, int $index): ?string
{
    for ($i = $index + 1, $count = count($tokens); $i < $count; $i++) {
        if ($tokens[$i] === ';') {
            return null;
        }
        if (is_array($tokens[$i]) && $tokens[$i][0] === T_AS) {
            return traitShadowDeclaredName($tokens, $i);
        }
    }

    return null;
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
        $alias = traitShadowAliasIn($tokens, $index);

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
    $files = BackendSourceFiles::all();

    expect(count($files))->toBeGreaterThan(2000, 'the backend walk read almost nothing — the roots are wrong, not the tree.');

    $scan = traitShadowScan($files);

    // 312 classes use one of the 40 first-party traits today. Read before the
    // verdict: the brace walk goes off by one on an interpolated string, and
    // when it does every class after it reads as having no methods at all —
    // which is an empty hit list and a clean-looking tree.
    expect($scan['using'])->toBeGreaterThan(100, 'almost no class was read as using a first-party trait — the declaration walk is broken, not the tree.');

    expect($scan['unresolved'])->toBe(
        [],
        "A first-party trait does not autoload, so every class using it is compared against no\n".
        "method names at all and passes this rule without being read. Fix the name or the psr-4\n".
        "mapping — a trait the autoloader cannot reach is also a trait `use` cannot apply:\n  ".
        implode("\n  ", $scan['unresolved']),
    );

    expect($scan['hits'])->toBe(
        [],
        "A class declares a method its trait already provides. PHP prefers the class, silently:\n".
        "no error, no warning, and every call site still reads `\$this->method()`. Name the\n".
        "difference, or move it into the trait so there is one answer. Offenders:\n  ".
        implode("\n  ", $scan['hits']),
    );
});

it('sees a planted shadow, including through an interpolated string and past an aliased import', function (): void {
    $planted = tempnam(sys_get_temp_dir(), 'trait-shadow').'.php';
    // The `as` is load-bearing: one aliased import used to key every other
    // import in the file under that alias, so the trait below resolved to
    // Planted\CoercesScalars, did not autoload, and the class was waved on.
    file_put_contents($planted, <<<'PHP'
        <?php
        namespace Planted;
        use Modules\Core\Public\Concerns\CoercesScalars;
        use Modules\Core\Public\Support\PatternScan as Scan;
        final class PlantedTraitShadow
        {
            use CoercesScalars;

            public function label(mixed $value): string
            {
                return "value: {$value}".Scan::class;
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

    expect($found)->toHaveCount(1, 'The reader must flag the shadowed toString() and nothing else in the planted class.');
    expect($found[0])->toContain('redeclares Modules\Core\Public\Concerns\CoercesScalars::toString()');
});
