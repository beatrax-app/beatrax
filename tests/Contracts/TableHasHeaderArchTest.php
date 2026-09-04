<?php

declare(strict_types=1);

use Modules\Core\Public\Support\MarkupSource;

// A data table without header cells announces every cell as bare text, so a
// screen reader cannot say which column a figure belongs to. Sonar's S5256
// checks this, but it reads the raw template and resolves neither <x-core::th>
// nor a `head` slot, so it is excluded and this stands in for it.

/**
 * @return list<string>
 */
function tableHeaderBladeFiles(): array
{
    $root = dirname(__DIR__, 2);
    $files = [];

    foreach (['Modules', 'resources'] as $dir) {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root.'/'.$dir, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    sort($files);

    return $files;
}

// Prose mentions a <table> often enough to matter: three of the counted
// elements under Modules/ turned out to be examples inside comments.
function tableHeaderStripComments(string $source): string
{
    $withoutBlade = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? $source;

    return preg_replace('/<!--.*?-->/s', '', $withoutBlade) ?? $withoutBlade;
}

it('gives every table a header source', function (): void {
    $offenders = [];

    foreach (tableHeaderBladeFiles() as $path) {
        $source = tableHeaderStripComments((string) file_get_contents($path));

        foreach (MarkupSource::elements($source, 'table') as $table) {
            $body = $table->inner;

            // Any of the three ways this codebase supplies header cells: a
            // literal <th>, the shared component, or the data-table head slot.
            $hasHeader = $body !== null && (
                MarkupSource::elements($body, 'th') !== []
                || MarkupSource::elements($body, 'x-core::th') !== []
                || str_contains($body, '$head')
            );

            if (! $hasHeader) {
                $offenders[] = str_replace(dirname(__DIR__, 2).'/', '', $path).':'.$table->line($source);
            }
        }
    }

    expect($offenders)->toBe([], sprintf(
        "a <table> with no header cells:\n  - %s",
        implode("\n  - ", $offenders),
    ));
});
