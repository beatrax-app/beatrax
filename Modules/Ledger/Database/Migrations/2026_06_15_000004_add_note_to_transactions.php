<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds a user-writable `note` column to `transactions` (D-04, OQ-A).
 *
 * CRITICAL: SQLite rebuilds the entire table on any column-add via
 * Blueprint::table() and SILENTLY DROPS all triggers in the process.
 * This migration MUST re-CREATE all four transactions_*_check_* triggers
 * immediately after the column-add to restore the enum invariants:
 *
 *   - transactions_type_check_insert
 *   - transactions_type_check_update
 *   - transactions_payment_type_check_insert
 *   - transactions_payment_type_check_update
 *
 * This migration's timestamp (2026_06_15_000004) is later than
 * 2026_05_17_020001_recreate_transactions_type_triggers.php, making it
 * the new last-toucher of transactions — it owns the trigger re-install
 * from this point forward. Future ALTERs on transactions must either
 * timestamp before this migration, or also re-install the triggers.
 *
 * The `note` column is a nullable TEXT placed after `description`.
 * A blank note from the UI should be stored as NULL (not empty string)
 * to allow IS NULL checks.
 *
 * The same trigger re-install pattern is applied in down() because
 * dropping a column also rebuilds the table and drops the triggers.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->text('note')->nullable()->after('description');
        });

        // SQLite column-add rebuilt the table and dropped all triggers.
        // Re-install all four transactions_*_check_* triggers immediately.
        $connection = $this->db()->connection($this->getConnection());

        // --- type triggers (identical DDL to 2026_05_17_020001_recreate_transactions_type_triggers.php) ---
        $connection->statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS transactions_type_check_update');

        $allowedTypes = "'expense','income','transfer_out','transfer_in','fee','refund','adjustment'";
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_type_check_update BEFORE UPDATE OF type ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));

        // --- payment_type triggers (DDL from database/schema/sqlite-schema.sql lines 1080-1085) ---
        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_update');

        $allowedPaymentTypes = "'pin','online','transfer','direct_debit','cash','fee','refund','unknown'";
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_payment_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.payment_type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END",
            $allowedPaymentTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_payment_type_check_update BEFORE UPDATE OF payment_type ON transactions FOR EACH ROW
             WHEN NEW.payment_type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END",
            $allowedPaymentTypes,
        ));
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('note');
        });

        // Column-drop also rebuilds the table and drops all triggers.
        // Re-install all four triggers in down() as well.
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS transactions_type_check_update');

        $allowedTypes = "'expense','income','transfer_out','transfer_in','fee','refund','adjustment'";
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_type_check_update BEFORE UPDATE OF type ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));

        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_update');

        $allowedPaymentTypes = "'pin','online','transfer','direct_debit','cash','fee','refund','unknown'";
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_payment_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.payment_type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END",
            $allowedPaymentTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER transactions_payment_type_check_update BEFORE UPDATE OF payment_type ON transactions FOR EACH ROW
             WHEN NEW.payment_type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END",
            $allowedPaymentTypes,
        ));
    }
};
