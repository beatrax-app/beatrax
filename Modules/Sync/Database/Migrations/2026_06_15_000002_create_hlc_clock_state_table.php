<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // Raw SQL because Blueprint cannot express a compound, non-
        // auto-increment primary key. The earlier singleton shape
        // (id INTEGER PRIMARY KEY CHECK (id = 1)) threw the moment a second
        // (user_id, device_id) recorded an op — hence one row per pair.
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
