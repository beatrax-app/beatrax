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
