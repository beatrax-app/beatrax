<?php

declare(strict_types=1);

// The architecture page called IndexTransactionOnImport "the one production
// caller of the writer" while eight call sites in five modules were reindexing
// on note edits, deletes, delimiter sweeps and op-log replays. A reader who
// believed the page would ship a write that leaves the index stale, and only a
// search would ever show it.

/**
 * @return list<string>
 */
function writerCallerPhpFiles(): array
{
    $files = [];
    /** @var SplFileInfo $file */
    foreach (new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(base_path('Modules'), RecursiveDirectoryIterator::SKIP_DOTS),
    ) as $file) {
        $path = $file->getPathname();
        if (! $file->isFile() || ! str_ends_with($path, '.php')) {
            continue;
        }
        // Locale dictionaries are three quarters of the tree by file count and
        // hold nothing but translated strings, so reading them is IO spent to
        // learn nothing.
        if (str_contains($path, '/tests/') || str_contains($path, '/Database/') || str_contains($path, '/Resources/lang/')) {
            continue;
        }
        $files[] = $path;
    }
    sort($files);

    return $files;
}

/**
 * @return list<string> the short class name of every production caller
 */
function writerCallerClasses(): array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }

    $callers = [];

    foreach (writerCallerPhpFiles() as $path) {
        $source = (string) file_get_contents($path);

        if (preg_match('/->(?:upsert|delete)ForTransaction\(/', $source) !== 1) {
            continue;
        }
        if (preg_match('/^(?:final\s+|abstract\s+|readonly\s+)*(?:class|interface|trait|enum)\s+(\w+)/m', $source, $name) !== 1) {
            continue;
        }

        $callers[] = $name[1];
    }

    $callers = array_values(array_unique($callers));
    sort($callers);
    $cached = $callers;

    return $callers;
}

it('finds the callers it is meant to be counting', function (): void {
    expect(writerCallerClasses())->toContain('IndexTransactionOnImport');
    expect(count(writerCallerClasses()))->toBeGreaterThan(1);
});

it('names every production caller of the search index writer', function (): void {
    $page = (string) file_get_contents(base_path('.docs/features/search/architecture.md'));

    $unnamed = array_values(array_filter(
        writerCallerClasses(),
        static fn (string $caller): bool => ! str_contains($page, $caller),
    ));

    expect($unnamed)->toBe([], implode("\n", [
        'These classes call SearchIndexWriterContract and .docs/features/search/architecture.md',
        'does not name them:',
        ...$unnamed,
        '',
        'The page once said the import listener was the only caller. Every write that',
        'changes indexed text has to reindex, so a page that names one of them teaches',
        'a reader to ship the stale-index bug the others exist to avoid.',
    ]));
});
