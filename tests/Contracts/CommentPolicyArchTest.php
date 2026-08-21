<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/00-index.md
 */

/** @return list<string> absolute paths to in-scope backend PHP files */
function commentPolicyBackendFiles(): array
{
    $roots = [base_path('Modules'), base_path('app')];
    $files = [];
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
            if (! $file->isFile() || ! str_ends_with($path, '.php')) {
                continue;
            }
            if (str_contains($path, '/tests/') || str_contains($path, '/Database/Migrations/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

/** @return bool whether the comment text is a machine directive exempt from M1-M4 */
function commentPolicyIsDirective(string $text): bool
{
    return preg_match(
        '#^\s*(?://|/\*\*?)\s*@?(phpstan|psalm|phpcs|codeCoverage|var)\b#i',
        $text,
    ) === 1;
}

/** @return list<string> absolute paths to every Pest test file in the repo */
function commentPolicyTestFiles(): array
{
    $roots = [base_path('Modules'), base_path('tests')];
    $files = [];
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
            if (str_starts_with($path, base_path('Modules')) && ! str_contains($path, '/tests/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// Standards names share the LETTERS-DIGITS shape a requirement id has, and a
// test that proves something about SHA-256 has to be allowed to say so.
const COMMENT_POLICY_STANDARDS_NAMES = [
    'AES-128', 'AES-256', 'BIP-39', 'ISO-8601', 'ISO-4217', 'RFC-822', 'RFC-2822',
    'SHA-1', 'SHA-256', 'SHA-384', 'SHA-512', 'UTF-8', 'UTF-16', 'UTF-32',
    'X-25519', 'ED-25519', 'HTTP-2', 'CAMT-053', 'MT-940', 'PBKDF2-1', 'BASE-64',
];

/** @return list<string> the requirement identifiers a test name carries, if any */
function commentPolicyRequirementIds(string $name): array
{
    $haystack = str_replace(COMMENT_POLICY_STANDARDS_NAMES, '', $name);

    preg_match_all(
        '/\b[A-Z]{1,6}-\d[0-9A-Za-z.\-]*|\b(?:Phase|Req|Plan|UAT|Spec|Pitfall)\s+\d[0-9.]*/',
        $haystack,
        $matches,
    );

    /** @var list<string> $ids */
    $ids = $matches[0];

    return $ids;
}

/** @return list<array{file: string, line: int, name: string}> */
function commentPolicyTestNames(string $path): array
{
    $tokens = token_get_all((string) file_get_contents($path));
    $names = [];
    $count = count($tokens);

    for ($i = 0; $i < $count; $i++) {
        $token = $tokens[$i];
        if (! is_array($token) || $token[0] !== T_STRING) {
            continue;
        }
        if (! in_array($token[1], ['it', 'test', 'describe'], true)) {
            continue;
        }

        // `$this->it(...)` and `Foo::test(...)` are not the Pest functions.
        $prev = $tokens[$i - 1] ?? null;
        if (is_array($prev) && in_array($prev[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)) {
            continue;
        }

        $literal = $tokens[$i + 2] ?? null;
        if (($tokens[$i + 1] ?? null) !== '(' || ! is_array($literal) || $literal[0] !== T_CONSTANT_ENCAPSED_STRING) {
            continue;
        }

        $names[] = [
            'file' => $path,
            'line' => $literal[2],
            'name' => trim($literal[1], "'\""),
        ];
    }

    return $names;
}

$bannedTokens = '/\b(TODO|FIXME|HACK|XXX|@todo)\b|\b[A-Z]{2,}-\d+\b|\bPhase\s+\d|\bD-\d|\b[A-Z]{2,4}-\d{2}\b/';

it('has no banned deferral or provenance tokens in comments (M5)', function () use ($bannedTokens): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match($bannedTokens, $token[1]) === 1) {
                $hits[] = $path.':'.$token[2];
            }
        }
    }
    expect($hits)->toBe([], "Comments must carry no TODO/ticket/phase provenance. Offenders:\n  ".implode("\n  ", $hits));
});

// A test name is read at a failure, where the useful thing to know is what
// broke — not which requirement row the test traces back to.
it('has no requirement identifiers in test names', function (): void {
    $hits = [];
    foreach (commentPolicyTestFiles() as $path) {
        foreach (commentPolicyTestNames($path) as $entry) {
            $ids = commentPolicyRequirementIds($entry['name']);
            if ($ids !== []) {
                $hits[] = $entry['file'].':'.$entry['line'].' → '.implode(', ', $ids);
            }
        }
    }
    expect($hits)->toBe([], "A test name says what the test proves, never which requirement it traces to — identifiers belong in the commit trailer and the PR body. Offenders:\n  ".implode("\n  ", $hits));
});

it('has no informative /* */ block comments (M3)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_COMMENT) {
                continue;
            }
            if (str_starts_with(ltrim($token[1]), '/*')) {
                $hits[] = $path.':'.$token[2];
            }
        }
    }
    expect($hits)->toBe([], "Use /** */ PHPDoc, never informative /* */ blocks. Offenders:\n  ".implode("\n  ", $hits));
});

it('has no // block over 4 lines (M2)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        $lines = [];
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (is_array($token) && $token[0] === T_COMMENT && str_starts_with(ltrim($token[1]), '//')
                && ! commentPolicyIsDirective($token[1])) {
                $lines[] = $token[2];
            }
        }
        sort($lines);
        $block = [];
        $flush = function () use (&$block, &$hits, $path): void {
            $n = count($block);
            if ($n > 4) {
                $hits[] = $path.':'.$block[0]." ({$n}-line // block > 4)";
            }
            $block = [];
        };
        foreach ($lines as $line) {
            if ($block !== [] && $line !== end($block) + 1) {
                $flush();
            }
            $block[] = $line;
        }
        $flush();
    }
    expect($hits)->toBe([], "An inline // block is at most 4 lines. A comment worth only one line should BE one line — padding it to reach a floor is how this file used to manufacture the noise it exists to prevent. Offenders:\n  ".implode("\n  ", $hits));
});

it('has @-tag-only docblocks with no descriptive prose (M4)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            $seenTag = false;
            $hasContent = false;
            foreach (explode("\n", $token[1]) as $raw) {
                $line = trim(ltrim(trim($raw), '/*'));
                if ($line === '') {
                    continue;
                }
                $hasContent = true;
                if (str_starts_with($line, '@')) {
                    $seenTag = true;
                } elseif (! $seenTag) {
                    $hits[] = $path.':'.$token[2];
                    break;
                }
            }
            if ($hasContent && ! $seenTag && ! in_array($path.':'.$token[2], $hits, true)) {
                $hits[] = $path.':'.$token[2].' (docblock with no @-tags)';
            }
        }
    }
    expect($hits)->toBe([], "Docblocks must be @-tag only. Offenders:\n  ".implode("\n  ", $hits));
});

it('has every @link .md target resolving to a real .docs file (M6)', function (): void {
    $hits = [];
    foreach (commentPolicyBackendFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || $token[0] !== T_DOC_COMMENT) {
                continue;
            }
            if (preg_match_all('/@link\s+(\S+\.md)/', $token[1], $m) === 0) {
                continue;
            }
            foreach ($m[1] as $target) {
                $resolved = realpath(dirname($path).'/'.$target);
                if ($resolved === false || ! is_file($resolved)) {
                    $hits[] = $path.':'.$token[2].' → '.$target;
                }
            }
        }
    }
    expect($hits)->toBe([], "@link .md targets must exist under .docs. Broken links:\n  ".implode("\n  ", $hits));
});
