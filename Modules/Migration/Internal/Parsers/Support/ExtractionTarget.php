<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Parsers\Support;

/**
 * @link ../../../../../.docs/features/migration/reading-a-zip-without-ext-zip.md
 */
final class ExtractionTarget
{
    private const int DIRECTORY_MODE = 0o700;

    public static function directory(string $directory, string $name): bool
    {
        $target = self::pathOf($directory, $name);

        return is_dir($target) || @mkdir($target, self::DIRECTORY_MODE, true);
    }

    // A parent that cannot be made and a file that cannot be opened are the
    // same answer: there is nowhere to put this entry.
    /**
     * @return resource|false
     */
    public static function open(string $directory, string $name)
    {
        $target = self::pathOf($directory, $name);
        $parent = dirname($target);

        return is_dir($parent) || @mkdir($parent, self::DIRECTORY_MODE, true)
            ? @fopen($target, 'wb')
            : false;
    }

    private static function pathOf(string $directory, string $name): string
    {
        return $directory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $name);
    }
}
