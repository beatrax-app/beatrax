<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Support;

// A migrated row carries `migration_<product>` in source_format, written by the
// promote pipeline into both transactions and import_runs and recognised again
// by the notification that announces the batch. Two writers and one reader is
// three spellings unless the prefix lives in one place.
final class MigrationSourceFormat
{
    private const string PREFIX = 'migration_';

    public static function forProduct(string $sourceProduct): string
    {
        return self::PREFIX.$sourceProduct;
    }

    public static function names(string $sourceFormat): bool
    {
        return str_starts_with($sourceFormat, self::PREFIX);
    }
}
