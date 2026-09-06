<?php

declare(strict_types=1);

// Pest loads every test file it runs into ONE global namespace, so two files
// declaring the same helper is a fatal at bootstrap: the whole shard dies
// before a single test runs, reporting no test names and no assertion counts.
// It only shows when both files land in the same shard, so it survives every
// run of either file on its own.

/**
 * Every file Pest loads into that one namespace: the *Test.php files and the
 * Pest.php bootstraps beside them, which declare helpers too — `tests/Pest.php`
 * declares two — and were read by nothing here, so a *Test.php redeclaring one
 * of them was a fatal this rule could not see.
 *
 * @return array<string, list<string>> helper name => the files declaring it
 */
function globalTestHelperDeclarations(): array
{
    $roots = [base_path('Modules'), base_path('tests')];
    $declarations = [];

    foreach ($roots as $root) {
        if (! is_dir($root)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();

            if (! $file->isFile() || (! str_ends_with($path, 'Test.php') && ! str_ends_with($path, '/Pest.php'))) {
                continue;
            }

            foreach (topLevelFunctionNames($path) as $name) {
                $declarations[$name][] = str_replace(base_path().'/', '', $path);
            }
        }
    }

    return $declarations;
}

/**
 * @return list<string>
 */
function topLevelFunctionNames(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $names = [];
    $depth = 0;

    foreach ($tokens as $index => $token) {
        // `"{$x}"` and `"${x}"` open as their own token types and close as a
        // plain `}`, so counting only the literal brace drives the depth
        // negative and every later method reads as a top-level function.
        if ($token === '{'
            || (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $depth++;

            continue;
        }

        if ($token === '}') {
            $depth--;

            continue;
        }

        // Only a declaration outside every brace is global. A closure has no
        // name token after `function`, and a method sits inside a class body,
        // so both fail one of these two tests.
        if ($depth !== 0 || ! is_array($token) || $token[0] !== T_FUNCTION) {
            continue;
        }

        $name = nextNameToken($tokens, $index);

        if ($name !== null) {
            $names[] = $name;
        }
    }

    return $names;
}

/**
 * @param  array<int, array{0: int, 1: string, 2: int}|string>  $tokens
 */
function nextNameToken(array $tokens, int $index): ?string
{
    $count = count($tokens);

    for ($i = $index + 1; $i < $count; $i++) {
        $token = $tokens[$i];

        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        if (is_array($token) && $token[0] === T_STRING) {
            return $token[1];
        }

        return null;
    }

    return null;
}

it('gives every global test helper a single owning file', function (): void {
    $clashes = [];
    $declarations = globalTestHelperDeclarations();

    // Thousands of free helper functions carry this suite. A run that read a
    // handful found no clash because it stopped, not because every name is owned.
    expect(count($declarations))->toBeGreaterThan(
        500,
        'The walk found almost no global test helper, so the empty clash list below is a suite nobody read.',
    );

    foreach ($declarations as $name => $files) {
        $files = array_values(array_unique($files));

        if (count($files) > 1) {
            $clashes[] = $name.'() in '.implode(' AND ', $files);
        }
    }

    sort($clashes);

    expect($clashes)->toBe([], implode("\n", [
        'Two test files declare the same global helper. Pest loads them into one',
        'namespace, so the second declaration is a FATAL that takes the whole shard',
        'with it — no test names, no assertions, no clue which file caused it. Running',
        'either file alone still passes, which is why this only ever fails in CI.',
        '',
        'Give the helper a prefix nothing else uses, named for the file it serves.',
        'Clashes:',
        '  '.implode("\n  ", $clashes),
    ]));
});

// The reader counts brace depth over a token stream, and a reader that lost its
// depth reports every method as a global function or none at all. Both are the
// same answer a clean file gives, so each is planted here.
it('reads a declaration outside every brace and nothing inside one', function (): void {
    $probe = tempnam(sys_get_temp_dir(), 'helper-names').'.php';

    try {
        file_put_contents($probe, <<<'PHP'
            <?php

            function ownedHelperOne(): string
            {
                $interpolated = "a {$brace} and a ${older} one";

                return $interpolated;
            }

            final class NotAHelper
            {
                public function methodIsNotGlobal(): void {}
            }

            $closure = function (): void {};

            function ownedHelperTwo(): void {}
            PHP);

        expect(topLevelFunctionNames($probe))->toBe(
            ['ownedHelperOne', 'ownedHelperTwo'],
            'the reader must see both global helpers and none of: a method, a closure, or a name after an '
            .'interpolated brace it lost count of',
        );
    } finally {
        @unlink($probe);
    }
});
