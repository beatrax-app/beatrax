<?php

declare(strict_types=1);

// Pest loads every test file it runs into ONE global namespace, so two files
// declaring the same helper is a fatal at bootstrap: the whole shard dies
// before a single test runs, reporting no test names and no assertion counts.
// It only shows when both files land in the same shard, so it survives every
// run of either file on its own.

/**
 * @return array<string, list<string>> helper name => the test files declaring it
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

            if (! $file->isFile() || ! str_ends_with($path, 'Test.php')) {
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

    foreach (globalTestHelperDeclarations() as $name => $files) {
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
