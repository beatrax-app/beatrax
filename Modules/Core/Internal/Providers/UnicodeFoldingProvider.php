<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;
use Modules\Core\Public\Support\SqliteDatabase;
use Modules\Core\Public\Support\UnicodeFolding;

// A SQLite user function lives on one connection, so a search that folds case
// through one reads differently on a connection nobody registered it on -- and
// SQLite says so by refusing the query, never by returning fewer rows. This is
// the only place it is bound.
final class UnicodeFoldingProvider extends ServiceProvider
{
    // ConnectionEstablished is the one event every way of getting a connection
    // passes through: a first resolve, DatabaseManager::reconnect(), the
    // reconnector a lost connection calls, and connectUsing()/build().
    public function boot(Dispatcher $events, DatabaseManager $db): void
    {
        $events->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            self::registerOnSqlite($event->connection);
        });

        // What was open before that listener existed -- the desktop's, which
        // NativePHP opens inside its own boot(). AlreadyOpenConnectionsProvider
        // replays the event for most of them and cannot for one inside a
        // transaction, where the pragmas it replays for are illegal.
        $this->app->booted(static function () use ($db): void {
            foreach ($db->getConnections() as $connection) {
                self::registerOnSqlite($connection);
            }
        });
    }

    private static function registerOnSqlite(Connection $connection): void
    {
        if ($connection->getDriverName() !== SqliteDatabase::DRIVER) {
            return;
        }

        UnicodeFolding::registerOn($connection);
    }
}
