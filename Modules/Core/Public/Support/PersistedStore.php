<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The durable store's shape, in one place because two very different readers
// have to agree on it: UserDataPathService builds the paths PHP writes, and
// scripts/nativephp_exclude_data_from_backup.php flags the same tree in the iOS
// shell so iCloud never copies it. They disagreed, and the ledger was backed up.
/**
 * @link ../../../../.docs/features/core/durable-user-data-paths.md
 */
final class PersistedStore
{
    public const string DIRECTORY = 'persisted_data';

    public const string DATABASE_FILE = 'database/database.sqlite';

    public const string DURABLE_APP = 'storage/app';

    // What the native shell creates and excludes before the PHP runtime opens
    // anything. Slash-separated because a shell reads them, not a filesystem.
    /**
     * @return list<string>
     */
    public static function relativeDirectories(): array
    {
        return [dirname(self::DATABASE_FILE), self::DURABLE_APP];
    }

    // `$bundleRoot` is `base_path()` — the bundle an install wipes and re-ships.
    // The store is its SIBLING, and that is the whole reason it survives.
    public static function rootBeside(string $bundleRoot): string
    {
        return dirname($bundleRoot).DIRECTORY_SEPARATOR.self::DIRECTORY;
    }

    public static function pathBeside(string $bundleRoot, string $relative): string
    {
        return self::rootBeside($bundleRoot)
            .DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }
}
