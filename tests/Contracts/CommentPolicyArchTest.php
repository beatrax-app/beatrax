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

// The identifier ban reaches further than the style rules: config/ and routes/
// carry no class the boundary rules care about, but they carry comments, and a
// requirement id was hiding in config/nativephp.php precisely because nothing
// looked there.
/** @return list<string> absolute paths to every PHP file the identifier ban covers */
function commentPolicyIdentifierFiles(): array
{
    $files = commentPolicyBackendFiles();
    foreach ([base_path('config'), base_path('routes')] as $root) {
        if (! is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            $path = $file->getPathname();
            if ($file->isFile() && str_ends_with($path, '.php')) {
                $files[] = $path;
            }
        }
    }
    sort($files);

    return $files;
}

/** @return list<string> absolute paths to every in-scope Blade template */
function commentPolicyBladeFiles(): array
{
    $roots = [base_path('Modules'), base_path('resources')];
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
            if (! $file->isFile() || ! str_ends_with($path, '.blade.php')) {
                continue;
            }
            if (str_contains($path, '/vendor/') || str_contains($path, '/node_modules/')) {
                continue;
            }
            $files[] = $path;
        }
    }
    sort($files);

    return $files;
}

// A Blade comment is T_INLINE_HTML to the PHP tokeniser, so the token-based
// passes below cannot see one. It has to be lifted out of the raw source.
/** @return list<array{line: int, text: string}> every {{-- --}} block in a Blade file */
function commentPolicyBladeComments(string $path): array
{
    $source = (string) file_get_contents($path);
    if (preg_match_all('/\{\{--.*?--\}\}/s', $source, $matches, PREG_OFFSET_CAPTURE) === 0) {
        return [];
    }

    $comments = [];
    /** @var array{0: string, 1: int} $match */
    foreach ($matches[0] as $match) {
        $comments[] = [
            'line' => substr_count(substr($source, 0, $match[1]), "\n") + 1,
            'text' => $match[0],
        ];
    }

    return $comments;
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
// test that proves something about SHA-256 has to be allowed to say so. R-7 is
// the quantile definition, not a requirement.
const COMMENT_POLICY_STANDARDS_NAMES = [
    'AES-128', 'AES-256', 'BIP-39', 'ISO-8601', 'ISO-4217', 'RFC-822', 'RFC-2822',
    'SHA-1', 'SHA-256', 'SHA-384', 'SHA-512', 'UTF-8', 'UTF-16', 'UTF-32',
    'X-25519', 'ED-25519', 'HTTP-2', 'CAMT-053', 'MT-940', 'PBKDF2-1', 'BASE-64',
    'BCP-47', 'PSR-3', 'PSR-4', 'PSR-12', 'R-7',
];

/** @return string the text with every standards name blanked out */
function commentPolicyWithoutStandards(string $text): string
{
    return str_replace(COMMENT_POLICY_STANDARDS_NAMES, '', $text);
}

/** @return list<string> the requirement identifiers a test name carries, if any */
function commentPolicyRequirementIds(string $name): array
{
    $haystack = commentPolicyWithoutStandards($name);

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

// Every identifier shape this repo actually mints: D-06 and T-05-12 (one
// letter), WR-11 and GOV-R12 (several), F3-R36 (letter then digit). The
// one-letter form demands two digits so N-1 and R-7 stay prose. Phase, Wave
// and friends are provenance whatever number follows them.
$bannedTokens = '/\b(TODO|FIXME|HACK|XXX|@todo)\b'
    .'|\b(?:[A-Z]{2,6}\d{0,2}|[A-Z]\d{1,2})-[A-Z]{0,2}\d{1,3}\b'
    .'|\b[A-Z]-(?:[A-Z]{1,2}\d{1,3}|\d{2,3})\b'
    .'|(?i:\b(?:Phase|Wave|Plan|Pitfall|Req|Issue|UAT)\s+#?\d)/';

it('has no banned deferral or provenance tokens in comments (M5)', function () use ($bannedTokens): void {
    $hits = [];
    foreach (commentPolicyIdentifierFiles() as $path) {
        foreach (token_get_all((string) file_get_contents($path)) as $token) {
            if (! is_array($token) || ! in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (preg_match($bannedTokens, commentPolicyWithoutStandards($token[1])) === 1) {
                $hits[] = $path.':'.$token[2];
            }
        }
    }
    expect($hits)->toBe([], "Comments must carry no TODO/ticket/phase provenance. Offenders:\n  ".implode("\n  ", $hits));
});

// Held back, not exempt: these four are mid-move to another module, so their
// identifiers come out with the move rather than against it. The list only
// ever shrinks — a fifth file cannot be added without this line changing.
const COMMENT_POLICY_BLADE_HELD_FOR_MOVE = [
    'Modules/Core/Resources/views/livewire/app-sidebar.blade.php',
    'Modules/Core/Resources/views/livewire/dashboard.blade.php',
    'Modules/Core/Resources/views/livewire/net-worth-card.blade.php',
    'Modules/Core/Resources/views/livewire/settings-page.blade.php',
];

// A Blade file ends in .php and so was always in scope, but its comments are
// invisible to the tokeniser — which is how 250-odd of them accumulated.
it('has no banned deferral or provenance tokens in Blade comments (M5)', function () use ($bannedTokens): void {
    $hits = [];
    foreach (commentPolicyBladeFiles() as $path) {
        $held = false;
        foreach (COMMENT_POLICY_BLADE_HELD_FOR_MOVE as $suffix) {
            $held = $held || str_ends_with($path, $suffix);
        }
        if ($held) {
            continue;
        }
        foreach (commentPolicyBladeComments($path) as $comment) {
            if (preg_match($bannedTokens, commentPolicyWithoutStandards($comment['text'])) === 1) {
                $hits[] = $path.':'.$comment['line'];
            }
        }
    }
    expect($hits)->toBe([], "A {{-- --}} comment says what the markup is for, never which requirement or phase it traces to — identifiers belong in the commit trailer and the PR body. A UI-SPEC §-section reference is a pointer into a living document and stays. Offenders:\n  ".implode("\n  ", $hits));
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
