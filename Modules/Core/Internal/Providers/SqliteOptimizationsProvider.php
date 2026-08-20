<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;

/**
 * @link ../../../../.docs/features/core/architecture.md
 */
final class SqliteOptimizationsProvider extends ServiceProvider
{
    // How long a blocked writer waits for the lock before giving up. Applied
    // to any connection that does not configure its own — the desktop runs
    // sync:serve, relay:serve, a queue worker and the app server against one
    // SQLite file, and at five seconds the relay lost whole pairing frames to
    // "database is locked" while the app server held a write.
    private const int DEFAULT_BUSY_TIMEOUT_MS = 30_000;

    public function boot(Dispatcher $events): void
    {
        $events->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            $connection = $event->connection;

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            // Read from the connection rather than imposed: this listener runs
            // AFTER the connector applied the configured value, so a fixed
            // number here silently overrode it. config/database.php asks for
            // thirty seconds and was being cut to five.
            $configured = $connection->getConfig('busy_timeout');
            $busyTimeout = is_numeric($configured) ? (int) $configured : self::DEFAULT_BUSY_TIMEOUT_MS;

            $connection->statement('PRAGMA journal_mode = WAL');
            $connection->statement('PRAGMA synchronous = NORMAL');
            $connection->statement('PRAGMA busy_timeout = '.$busyTimeout);
            $connection->statement('PRAGMA foreign_keys = ON');
            $connection->statement('PRAGMA temp_store = MEMORY');
        });
    }
}
