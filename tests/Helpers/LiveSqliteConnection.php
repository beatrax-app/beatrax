<?php

declare(strict_types=1);

namespace Tests\Helpers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;

// Backup, restore and install all act on the file behind the DEFAULT
// connection, because that is the one the app reads its ledger through — the
// desktop shell names its connection `nativephp`, not `sqlite`. Pointing only
// the connection spelled `sqlite` at a fixture is an arrangement no runtime
// has, and it is what let a nightly backup ship 4 KB files.
final class LiveSqliteConnection
{
    public const string NAME = 'sqlite';

    public const string TEST_DEFAULT = 'sqlite_testing';

    public static function pointAt(Application $app, string $path): void
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);
        $config->set('database.connections.'.self::NAME.'.database', $path);
        $config->set('database.default', self::NAME);

        /** @var DatabaseManager $db */
        $db = $app->make(DatabaseManager::class);
        $db->purge(self::NAME);
    }

    // Config only, on whatever connection is already default, and no purge:
    // for a guard that reads the configured path and refuses before opening
    // anything, pointing at a file that does not exist must not cost the test
    // its own connection.
    public static function pathOnDefault(Application $app, string $path): string
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);
        $default = $config->get('database.default');
        $key = 'database.connections.'.(is_string($default) ? $default : self::NAME).'.database';
        $previous = $config->get($key);
        $config->set($key, $path);

        return is_string($previous) ? $previous : '';
    }

    public static function restore(Application $app): void
    {
        /** @var Repository $config */
        $config = $app->make(Repository::class);
        $config->set('database.default', self::TEST_DEFAULT);

        /** @var DatabaseManager $db */
        $db = $app->make(DatabaseManager::class);
        $db->purge(self::NAME);
    }
}
