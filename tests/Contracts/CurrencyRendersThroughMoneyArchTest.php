<?php

declare(strict_types=1);

/*
 * Illuminate\Support\Number::currency() builds an intl NumberFormatter for the
 * locale it is handed, and throws when the runtime has no data for it. The
 * mobile PHP build ships ICU with English-only locale data, so every one of
 * these calls — all of them passing 'nl' — was a 500 on device while the same
 * page rendered fine on desktop.
 *
 * Money::format() is the seam that survives both runtimes: it asks ICU first
 * and renders the same string from marks the repo carries itself when ICU
 * cannot answer. Currency belongs there, not in a view.
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
