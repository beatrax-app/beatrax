<?php

declare(strict_types=1);

// Every chain_links row is written through ChainLinkInsertHelper, which is what
// makes the evidence JSON byte-identical and the pair-uniqueness guard one
// guard. The demo seeder wrote its own INSERT through an Eloquent cast (default
// json_encode flags, a narrower duplicate check) and the hint listener hand-
// copied both; the copies had already drifted apart from the original.

const CHAIN_LINK_INSERT_ALLOWED = 'Modules/Chains/Internal/ChainLinkInsertHelper.php';

/** @return array<string, string> repo-relative path => contents */
function chainLinkWriteSources(): array
{
    $sources = [];
    foreach (['Modules', 'app', 'database'] as $directory) {
        $root = base_path($directory);
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
            $relative = str_replace(base_path().'/', '', $path);
            // A test builds the rows it is about to read back, which is not a
            // production write path and never shares this guard.
            if (str_contains($relative, '/tests/') || $relative === CHAIN_LINK_INSERT_ALLOWED) {
                continue;
            }
            $sources[$relative] = (string) file_get_contents($path);
        }
    }

    return $sources;
}

it('has one chain_links INSERT site, and it is ChainLinkInsertHelper', function (): void {
    $patterns = [
        "#chain_links'\\s*\\)\\s*->\\s*insert(GetId|OrIgnore|Using)?\\s*\\(#",
        '#ChainLink::(query\(\)->)?(create|insert|forceCreate|firstOrCreate|updateOrCreate)\s*\(#',
    ];

    $offenders = [];
    foreach (chainLinkWriteSources() as $relative => $contents) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $offenders[] = $relative;
                break;
            }
        }
    }

    expect($offenders)->toBe([], implode("\n", [
        'These write chain_links without going through '.CHAIN_LINK_INSERT_ALLOWED.':',
        ...$offenders,
    ]));
});
