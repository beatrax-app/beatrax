<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// `InboxMessageQuery::forStatus()` is user-agnostic by design — its consumer
// enforces the per-user scope — so `inbox_messages_user_id_status_index` cannot
// answer it, and the hourly walk read every message ever fetched to find the
// few still waiting. The set it looks for shrinks as the mailbox grows.
/**
 * @link ../../../../.docs/architecture/reads-bounded-by-the-user.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasTable('inbox_messages')) {
            return;
        }

        $this->db()->connection($this->getConnection())->statement(
            'CREATE INDEX IF NOT EXISTS inbox_messages_status_idx ON inbox_messages(status)'
        );
    }

    public function down(): void
    {
        $this->db()->connection($this->getConnection())->statement(
            'DROP INDEX IF EXISTS inbox_messages_status_idx'
        );
    }
};
