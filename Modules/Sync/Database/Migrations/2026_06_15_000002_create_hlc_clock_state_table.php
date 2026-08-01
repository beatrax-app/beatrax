<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the hlc_clock_state table — a per-(user_id, device_id) store for
 * the Hybrid Logical Clock's persisted (l, c) state (D-12, SYNC-01).
 *
 * The compound non-auto-increment PRIMARY KEY cannot be expressed cleanly
 * via Blueprint, so this migration uses a raw SQL CREATE TABLE statement.
 *
 * Design notes:
 *   - One row per (user_id, device_id) pair. The composite PRIMARY KEY
 *     (user_id, device_id) is what makes multi-device AND multi-user
 *     support possible (a hard project constraint: "schema must permit a
 *     second user later without migration pain"). A second device or a
 *     second user simply inserts its own row; with no shared singleton row,
 *     devices/users can never clobber each other's clock state.
 *   - The previous design used `id INTEGER PRIMARY KEY CHECK (id = 1)`,
 *     a singleton that threw a PK/CHECK violation the moment a second
 *     (user_id, device_id) wrote an op (CR-01). That singleton is gone.
 *   - `last_l` and `last_c` are the persisted HLC high-water marks.
 *     OpLogWriter reads them on boot and calls HybridLogicalClock::receive()
 *     before the first tick() — this is the monotonic guard that prevents
 *     a backwards wall-clock jump or app restart from rewinding the logical
 *     clock and breaking total order (Pitfall 6 / D-12).
 *   - `updated_at` is updated atomically within the same DB transaction as
 *     each op-log insert so the clock state always reflects the last
 *     committed entry.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement("
            CREATE TABLE hlc_clock_state (
                user_id    INTEGER NOT NULL,
                device_id  TEXT    NOT NULL,
                last_l     INTEGER NOT NULL DEFAULT 0,
                last_c     INTEGER NOT NULL DEFAULT 0,
                updated_at DATETIME NOT NULL DEFAULT (datetime('now')),
                PRIMARY KEY (user_id, device_id)
            )
        ");
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())
            ->statement('DROP TABLE IF EXISTS hlc_clock_state');
    }
};
