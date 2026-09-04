<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#a--comment-leading-an-alpine-expression
 */

/** @return list<string> */
function alpineBladeFiles(): array
{
    $files = [];

    foreach (['Modules', 'resources/views'] as $root) {
        $dir = base_path($root);

        if (! is_dir($dir)) {
            continue;
        }

        /** @var iterable<SplFileInfo> $it */
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));

        foreach ($it as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }
    }

    return $files;
}

it('never opens an Alpine expression with a line comment', function (): void {
    $offenders = [];

    foreach (alpineBladeFiles() as $path) {
        $contents = (string) file_get_contents($path);

        $matches = PatternScan::sets(
            '/\b(x-(?:init|effect|show|model|text|html|if|on:[\w.\-]+|bind:[\w.\-]+)|@[\w.\-]+)\s*=\s*"(\s*)\/\//',
            $contents,
        );

        if ($matches === []) {
            continue;
        }

        foreach ($matches as $match) {
            $offenders[] = str_replace(base_path().'/', '', $path).' → '.$match[1];
        }
    }

    expect(array_values(array_unique($offenders)))->toBe(
        [],
        "An Alpine expression cannot start with a `//` comment — the directive throws and\n".
        "silently never runs. Move the comment into a {{-- Blade comment --}} above the\n".
        "element:\n  ".implode("\n  ", array_unique($offenders)),
    );
});
