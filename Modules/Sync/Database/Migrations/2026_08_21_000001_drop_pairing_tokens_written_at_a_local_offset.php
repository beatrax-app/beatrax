<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    // expires_at is compared lexically, so a row written in a local offset
    // sorts by its own hour digits against a Zulu now: the TTL silently
    // stretches, or a live token is refused. Rows are dropped rather than
    // rewritten because pairing_tokens is a transient scratch table whose
    // permanent trust store is device_registry, and a handshake cannot
    // survive the restart this migration runs during anyway.
    public function up(): void
    {
        // A NULL expires_at makes `not like` evaluate to NULL rather than true,
        // so such a row would survive the sweep it is most obviously part of.
        // The column is NOT NULL today; the guard is what keeps that true if it
        // ever stops being.
        $this->db()->connection($this->getConnection())
            ->table('pairing_tokens')
            ->where('expires_at', 'not like', '%Z')
            ->orWhereNull('expires_at')
            ->delete();
    }

    // Deleted scratch rows cannot be brought back, and their TTL is ten
    // minutes: there is nothing for a rollback to restore.
    public function down(): void {}
};
