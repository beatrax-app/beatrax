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
