<?php

declare(strict_types=1);

namespace Modules\Migration\Tests\Support;

use RuntimeException;
use ZipArchive;

/**
 * Shared path/extraction helpers for the golden fixtures under
 * `Modules/Migration/tests/Fixtures/`, used by the Wave 0 RED parser/
 * feature tests (13.5-02 Task 3). Kept as a plain PSR-4 class (rather than
 * global test-file functions) so multiple test files can use identically
 * named helpers without a global-function collision.
 */
final class MigrationFixturePaths
{
    public static function root(): string
    {
        return dirname(__DIR__).'/Fixtures';
    }

    /**
     * The ynab4 fixture ships as a loose two-CSV folder (a valid YNAB4
     * export shape per RESEARCH.md — "a ZIP/folder containing two files")
     * so no extraction step is needed; the directory itself IS the
     * `$extractedPath` a `ParsesMigrationSource::parse()` expects.
     */
    public static function ynab4Dir(string $version): string
    {
        return self::root()."/ynab4/{$version}";
    }

    public static function nynabZip(string $version): string
    {
        return $version === 'v1'
            ? self::root().'/nynab/v1/nynab-export.zip'
            : self::root().'/nynab/v2/nynab-export-v2.zip';
    }

    public static function corruptZip(): string
    {
        return self::root().'/corrupt/not-a-real-export.zip';
    }

    /**
     * Extracts a ZIP fixture to a fresh temp directory and returns its
     * path — the caller is responsible for cleaning it up (or leaving it,
     * since it lives under the OS temp dir).
     */
    public static function extractZip(string $zipPath): string
    {
        $dir = sys_get_temp_dir().'/migration-fixture-extract-'.uniqid('', true);
        if (! mkdir($dir, 0755, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create extraction dir {$dir}");
        }

        $zip = new ZipArchive;
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Could not open zip {$zipPath}");
        }
        $zip->extractTo($dir);
        $zip->close();

        return $dir;
    }
}
