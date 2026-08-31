<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/peer-session-lifecycle.md#catch-up-an-hlc-watermark-exchange
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // How far this device has consumed of each AUTHOR's ops as delivered by
        // each PEER. A peer relays what other installs wrote, so one scalar per
        // peer is a claim the stream cannot make. Raw SQL for the compound
        // primary key, as hlc_clock_state does.
        $connection->statement("
            CREATE TABLE sync_peer_catch_up_state (
                user_id          INTEGER NOT NULL,
                peer_device_id   TEXT    NOT NULL,
                author_device_id TEXT    NOT NULL,
                last_l           INTEGER NOT NULL DEFAULT 0,
                last_c           INTEGER NOT NULL DEFAULT 0,
                updated_at       DATETIME NOT NULL DEFAULT (datetime('now')),
                PRIMARY KEY (user_id, peer_device_id, author_device_id)
            )
        ");
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())
            ->statement('DROP TABLE IF EXISTS sync_peer_catch_up_state');
    }
};
