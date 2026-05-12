<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;

/**
 * Applies the WAL + `synchronous=NORMAL` + `busy_timeout` + foreign-key
 * pragmas to every newly opened SQLite connection. Listens to Laravel's
 * `ConnectionEstablished` event so the pragmas are applied without relying
 * on a facade or a helper. Skips connections whose driver is not `sqlite`.
 */
final class SqliteOptimizationsProvider extends ServiceProvider
{
    public function boot(Dispatcher $events): void
    {
        $events->listen(ConnectionEstablished::class, static function (ConnectionEstablished $event): void {
            $connection = $event->connection;

            if ($connection->getDriverName() !== 'sqlite') {
                return;
            }

            $connection->statement('PRAGMA journal_mode = WAL');
            $connection->statement('PRAGMA synchronous = NORMAL');
            $connection->statement('PRAGMA busy_timeout = 5000');
            $connection->statement('PRAGMA foreign_keys = ON');
            $connection->statement('PRAGMA temp_store = MEMORY');
        });
    }
}
