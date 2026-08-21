<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

// The identity of the live SQLite database: the driver `config/database.php`
// declares for it, and the two config keys that carry that driver and the
// file's path. Backup and restore resolve the file they overwrite through
// those keys, so a drift between spellings loses a ledger instead of failing.
/**
 * @link ../../../../.docs/runbooks/operator-recovery.md
 */
final class SqliteDatabase
{
    public const string DRIVER = 'sqlite';

    public const string DRIVER_CONFIG_KEY = 'database.connections.sqlite.driver';

    public const string LIVE_PATH_CONFIG_KEY = 'database.connections.sqlite.database';
}
