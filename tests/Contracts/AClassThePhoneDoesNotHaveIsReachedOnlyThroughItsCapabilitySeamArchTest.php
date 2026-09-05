<?php

declare(strict_types=1);

/**
 * @link ../../.docs/conventions/invariants-from-shipped-failures.md
 * @link ../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */

// The NativePHP mobile PHP build carries no ext-zip: its php_config.h has
// `#undef HAVE_ZIP` on both iOS and Android. `new ZipArchive` there is not a
// caught failure, it is a bare PHP Error, and the screen that caught it told
// the reader their perfectly valid export was unreadable. Reading was the first
// direction to need a seam; writing one needed a second, so each pair below is
// a factory and the extension-backed half it picks.
const PHONELESS_CLASS_SEAMS = [
    'ZipArchive' => [
        'Modules/Migration/Internal/Parsers/Support/ZipArchiveReader.php',
        'Modules/Migration/Internal/Parsers/Support/ArchiveReaderFactory.php',
        'Modules/Core/Internal/Backup/ZipArchiveWriter.php',
        'Modules/Core/Internal/Backup/ArchiveWriterFactory.php',
    ],
];

/**
 * @return list<string> repo-relative paths to every shipped backend PHP file
 */
function phonelessClassScannedFiles(): array
{
    $files = [];
    foreach (['Modules', 'app'] as $root) {
        $directory = base_path($root);
        if (! is_dir($directory)) {
            continue;
        }

        $tree = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );
        foreach ($tree as $file) {
            $path = $file instanceof SplFileInfo ? $file->getPathname() : '';
            if ($path === '' || ! str_ends_with($path, '.php') || str_contains($path, '/tests/')) {
                continue;
            }

            $files[] = str_replace(base_path().'/', '', $path);
        }
    }
    sort($files);

    return $files;
}

it('reaches a class the phone build does not carry only through its capability seam', function (): void {
    $files = phonelessClassScannedFiles();
    expect($files)->not->toBe([]);

    $offenders = [];
    foreach ($files as $path) {
        $source = (string) file_get_contents(base_path($path));
        foreach (PHONELESS_CLASS_SEAMS as $class => $allowed) {
            if (in_array($path, $allowed, true)) {
                continue;
            }
            if (preg_match('/\b'.preg_quote($class, '/').'\b/', $source) === 1) {
                $offenders[] = "{$path} names {$class}";
            }
        }
    }

    expect($offenders)->toBe(
        [],
        "A class the mobile PHP build does not carry was named outside the one file allowed to ask for it.\n".
        "On a phone that line raises a bare PHP Error, not a typed failure, and whatever catch is above it\n".
        "reports a fault of ours as a fault of the reader's file. Reach it through ArchiveReaderFactory,\n".
        'which picks a reader the running build actually has, or add the new seam file to the list above.',
    );
});
