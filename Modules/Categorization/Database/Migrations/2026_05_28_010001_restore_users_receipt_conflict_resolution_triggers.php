<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Modules\Core\Database\Support\ModuleMigration;

// The later Auth migration dropping the uniquely-indexed `email` column
// rebuilds the whole `users` table, and a SQLite rebuild keeps the columns
// and indices but silently drops every trigger. These are byte-identical to
// the originals in 2026_05_17_010004, and re-creating them is a no-op.
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
