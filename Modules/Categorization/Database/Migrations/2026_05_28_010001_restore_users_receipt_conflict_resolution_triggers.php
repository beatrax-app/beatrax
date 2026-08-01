<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Re-establishes the two enum-check triggers on
 * `users.receipt_conflict_resolution`.
 *
 * The earlier migration `2026_05_17_010004_add_receipt_conflict_resolution_to_users.php`
 * creates them as BEFORE INSERT / BEFORE UPDATE guards on the enum.
 * The later Auth migration `2026_05_19_000001_drop_email_add_username_to_users_table.php`
 * uses `Schema::dropColumn('email')` on a uniquely-indexed column —
 * SQLite cannot drop a UNIQUE column natively (`ALTER TABLE DROP
 * COLUMN` refuses), so Laravel's schema builder rebuilds the entire
 * `users` table (CREATE NEW, COPY DATA, DROP OLD, RENAME). The rebuild
 * preserves columns and indices but DROPS triggers attached to the
 * pre-rebuild table.
 *
 * This migration re-creates both triggers with `IF EXISTS` cleanup so
 * it is safe to run against a database where the triggers are still
 * present (no-op) AND against one where they were dropped by the
 * rebuild (restores them). The trigger definitions are byte-identical
 * to the originals in `2026_05_17_010004_*`.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $allowed = "'unset','prefer_receipt','prefer_first_write'";

        $connection->statement('DROP TRIGGER IF EXISTS users_receipt_conflict_resolution_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS users_receipt_conflict_resolution_check_update');

        $connection->statement(sprintf(
            "CREATE TRIGGER users_receipt_conflict_resolution_check_insert BEFORE INSERT ON users FOR EACH ROW
             WHEN NEW.receipt_conflict_resolution NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END",
            $allowed,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER users_receipt_conflict_resolution_check_update BEFORE UPDATE OF receipt_conflict_resolution ON users FOR EACH ROW
             WHEN NEW.receipt_conflict_resolution NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid users.receipt_conflict_resolution value'); END",
            $allowed,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS users_receipt_conflict_resolution_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS users_receipt_conflict_resolution_check_insert');
    }
};
