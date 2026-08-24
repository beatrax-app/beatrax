<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Illuminate\Contracts\Config\Repository;

// The identity of the live SQLite database. It is NOT the connection called
// `sqlite`: the desktop shell registers its own connection named `nativephp`
// and makes that the default, leaving the literal `sqlite` entry pointing at a
// file nothing ever opens. Backup and restore resolve the file they overwrite
// through here, so asking for a spelling instead of the default loses a ledger
// — or, as it did, refuses the only platform the feature ships for.
/**
 * @link ../../../../.docs/runbooks/operator-recovery.md
 */
final class SqliteDatabase
{
    public const string DRIVER = 'sqlite';

    private const string DEFAULT_KEY = 'database.default';

    private const string IN_MEMORY = ':memory:';

    public static function connectionName(Repository $config): string
    {
        $default = $config->get(self::DEFAULT_KEY);

        return is_string($default) ? $default : '';
    }

    public static function isSqliteBuild(Repository $config): bool
    {
        $connection = self::connectionName($config);

        return $connection !== ''
            && $config->get(self::driverKey($connection)) === self::DRIVER;
    }

    public static function livePathKey(Repository $config): string
    {
        return 'database.connections.'.self::connectionName($config).'.database';
    }

    // An in-memory database has no file to copy over or snapshot, so it is not
    // a live path even though its driver is sqlite. The download path only
    // needs the driver and keeps working there.
    public static function livePath(Repository $config): ?string
    {
        if (! self::isSqliteBuild($config)) {
            return null;
        }

        $path = $config->get(self::livePathKey($config));

        return is_string($path) && $path !== '' && $path !== self::IN_MEMORY ? $path : null;
    }

    private static function driverKey(string $connection): string
    {
        return 'database.connections.'.$connection.'.driver';
    }
}
