<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the hlc_clock_state table — a singleton-row store for the
 * Hybrid Logical Clock's persisted (l, c) state (D-12, SYNC-01).
 *
 * The singleton-row constraint (CHECK id = 1) cannot be expressed via
 * Blueprint, so this migration uses a raw SQL CREATE TABLE statement.
 *
 * Design notes:
 *   - One row per (user_id, device_id) pair on this device. The CHECK
 *     (id = 1) ensures exactly one physical row exists.
 *   - `last_l` and `last_c` are the persisted HLC high-water marks.
 *     OpLogWriter reads them on boot and calls HybridLogicalClock::receive()
 *     before the first tick() — this is the monotonic guard that prevents
 *     a backwards wall-clock jump or app restart from rewinding the logical
 *     clock and breaking total order (Pitfall 6 / D-12).
 *   - `updated_at` is updated atomically within the same DB transaction as
 *     each op-log insert so the clock state always reflects the last
 *     committed entry.
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement("
            CREATE TABLE hlc_clock_state (
                id         INTEGER PRIMARY KEY CHECK (id = 1),
                user_id    INTEGER NOT NULL,
                device_id  TEXT    NOT NULL,
                last_l     INTEGER NOT NULL DEFAULT 0,
                last_c     INTEGER NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT (datetime('now'))
            )
        ");
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())
            ->statement('DROP TABLE IF EXISTS hlc_clock_state');
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
