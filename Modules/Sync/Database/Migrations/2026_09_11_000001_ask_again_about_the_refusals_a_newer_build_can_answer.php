<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

/**
 * @link ../../../../.docs/features/sync/architecture.md#retrying-a-create-two-devices-minted-one-id-for
 */
return new class extends ModuleMigration
{
    // The stamp says the quarantine was examined, and the recovery pass reads
    // nothing older than it. What examined those rows was a build that had no
    // re-home and no retry for a primary key two devices minted, so the stamp
    // is no longer true of them and a device with 52 held ops never asks again.
    public function up(): void
    {
        if (! $this->schema()->hasTable('sync_encryption_state')) {
            return;
        }

        $this->db()->connection($this->getConnection())
            ->table('sync_encryption_state')
            ->whereNotNull('history_reprojected_at')
            ->update(['history_reprojected_at' => null]);
    }

    // Nothing to restore: the value said when the last pass ran, and the next
    // one stamps its own the moment it does.
    public function down(): void {}
};
