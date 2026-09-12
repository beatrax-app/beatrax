<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Providers;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Support\ServiceProvider;

// NativePHP's provider makes `nativephp` the default connection and runs
// PRAGMA statements through it inside its own boot(), which is before this
// module attaches its ConnectionEstablished listeners. A resolved connection
// is cached, so the event never fires again for the one the desktop reads.
final class AlreadyOpenConnectionsProvider extends ServiceProvider
{
    // Registered last, so both listeners are attached when this runs. Measured
    // on the desktop beforehand: busy_timeout 5000 rather than the configured
    // 30000, synchronous FULL rather than NORMAL. Replay is otherwise safe --
    // the PRAGMA writes are idempotent and the check has its own boot flag.
    public function boot(Dispatcher $events, DatabaseManager $db): void
    {
        foreach ($db->getConnections() as $connection) {
            // SQLite refuses `PRAGMA synchronous` and `journal_mode` inside a
            // transaction -- "Safety level may not be changed inside a
            // transaction" -- and the optimisations listener writes both. A
            // connection mid-transaction was never the one NativePHP opened.
            if ($connection->transactionLevel() > 0) {
                continue;
            }

            $events->dispatch(new ConnectionEstablished($connection));
        }
    }
}
