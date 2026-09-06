<?php

declare(strict_types=1);

// `use PDO;` in a file with no namespace is a no-op PHP raises a warning for,
// and Pest turns that warning into an ErrorException at bootstrap. The whole
// parallel run then dies before a single test executes, printing no `Tests:`
// line at all -- so it does not read as a failing test, it reads as nothing
// having happened. A formatter added exactly this to a test file on this
// branch and the suite stopped running.

/** @return list<string> absolute paths to every test file, from both composer roots */
function testFilesCarryingImports(): array
{
    /** @var list<string> $files */
    $files = [];

    foreach (['Modules', 'tests'] as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        /** @var Iterator<SplFileInfo> $found */
        $found = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)),
            '/Test\.php$|\/Pest\.php$/',
        );

        foreach ($found as $file) {
            $files[] = $file->getPathname();
        }
    }

    sort($files);

    return $files;
}

/**
 * Read with the tokeniser and not with a pattern: `use` is four different
 * statements and only one of them is the hazard.
 *
 * @return list<array{name: string, line: int}> the global imports $source declares
 */
function nonCompoundImportsIn(string $source): array
{
    // A namespaced test file resolves its own names, so an import there is
    // ordinary. Only the namespace-less ones carry the hazard.
    if (preg_match('/^namespace\s+\S+;/m', $source) === 1) {
        return [];
    }

    $tokens = token_get_all($source);
    $depth = 0;
    $found = [];

    foreach ($tokens as $i => $token) {
        if ($token === '{') {
            $depth++;

            continue;
        }

        if ($token === '}') {
            $depth--;

            continue;
        }

        // Depth is what separates an import from a trait `use` inside a
        // class body -- and from a closure's `use (...)`, which is caught
        // by the paren below. Only a top-level one is the hazard.
        if ($depth !== 0 || ! is_array($token) || $token[0] !== T_USE) {
            continue;
        }

        $name = '';
        for ($j = $i + 1; $j < count($tokens); $j++) {
            $next = $tokens[$j];

            if ($next === ';' || $next === '(') {
                break;
            }

            if (is_array($next)) {
                $name .= $next[1];
            }
        }

        $name = trim($name);

        // A grouped or aliased import always carries a separator; `use
        // function x` and `use const x` name a global symbol deliberately.
        if ($name === '' || str_contains($name, '\\')
            || str_starts_with($name, 'function ') || str_starts_with($name, 'const ')) {
            continue;
        }

        $found[] = ['name' => $name, 'line' => $token[2]];
    }

    return $found;
}

it('leaves no non-compound import in a test file that declares no namespace', function (): void {
    $files = testFilesCarryingImports();

    // Read before the verdict: the offender list below is empty over an empty
    // walk, and the failure this rule exists for kills the run before any test
    // reports at all. The floor sits far under today's 2,561.
    expect(count($files))->toBeGreaterThan(
        500,
        'the walk found '.count($files).' test files, which is too few to be this suite.'
    );

    $offenders = [];

    foreach ($files as $path) {
        foreach (nonCompoundImportsIn((string) file_get_contents($path)) as $import) {
            $offenders[] = str_replace(base_path().'/', '', $path).':'.$import['line'].' — use '.$import['name'].';';
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These import a global name into a file that declares no namespace. PHP raises a warning,',
        'Pest turns it into an ErrorException at bootstrap, and the whole parallel run dies before',
        'a single test executes — printing no Tests: line, so it reads as nothing having happened:',
        ...$offenders,
        '',
        'Drop the import and spell the name inline (\\PDO), or give the double a home under',
        'Modules/<Module>/tests/Support/ and import it with a compound use.',
    ]));
});

// The tree holds none of these by construction — one would have stopped the run
// that reports this rule green — so the reader is driven against planted
// sources. The near-misses are the four `use` statements that are not the
// hazard: compound, aliased, a trait use inside a class, and a closure's.
it('tells a global import from the four other things `use` spells', function (): void {
    $names = array_map(
        static fn (array $import): string => $import['name'],
        nonCompoundImportsIn("<?php\n\nuse PDO;\nuse Generator;\n"),
    );

    expect($names)->toBe(['PDO', 'Generator'])
        ->and(nonCompoundImportsIn("<?php\n\nuse Modules\\Core\\Models\\User;\n"))->toBe([])
        ->and(nonCompoundImportsIn("<?php\n\nuse Modules\\Core\\Models\\User as Reader;\n"))->toBe([])
        ->and(nonCompoundImportsIn("<?php\n\nfinal class A { use B; }\n"))->toBe([])
        ->and(nonCompoundImportsIn("<?php\n\n\$f = function () use (\$x) { return \$x; };\n"))->toBe([])
        ->and(nonCompoundImportsIn("<?php\n\nnamespace Tests\\Support;\n\nuse PDO;\n"))->toBe([]);
});
