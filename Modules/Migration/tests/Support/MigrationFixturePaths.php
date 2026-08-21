<?php

declare(strict_types=1);

namespace Modules\Migration\Tests\Support;

use RuntimeException;
use ZipArchive;

// A shared class rather than global test helpers, so several test files can
// use these names without colliding.
final class MigrationFixturePaths
{
    public static function root(): string
    {
        return dirname(__DIR__).'/Fixtures';
    }

    // A YNAB4 export is a loose two-CSV folder, so there is nothing to extract:
    // the directory itself is the $extractedPath parse() expects.
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
