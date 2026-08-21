<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md#numbercurrency-on-the-mobile-icu-build
 */
it('renders no currency through the framework number helper', function (): void {
    $offenders = [];

    foreach (['Modules', 'app', 'resources'] as $root) {
        $directory = new RecursiveDirectoryIterator(base_path($root), FilesystemIterator::SKIP_DOTS);

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (str_contains($contents, 'Number::currency(')) {
                $offenders[] = str_replace(base_path().'/', '', $file->getPathname());
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "Number::currency() throws on a runtime whose ICU has no data for the\n".
        "locale. Render money through Money::format() instead, in:\n  ".
        implode("\n  ", $offenders),
    );
});
