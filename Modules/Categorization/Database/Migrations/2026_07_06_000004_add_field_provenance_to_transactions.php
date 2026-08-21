<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// A separate column from `auto_category_provenance`, which keeps its own
// shape and its own reader. SQLite rebuilds `transactions` on a column
// add or drop and silently drops every trigger with it, so up() and down()
// both reinstall them: this is the latest migration to touch the table.
/**
 * @link ../../../../.docs/features/categorization/field-provenance.md
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->json('field_provenance')->nullable()->after('auto_category_provenance');
        });

        $this->reinstallTransactionsTriggers();
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('field_provenance');
        });

        $this->reinstallTransactionsTriggers();
    }

    // DDL copied verbatim from 2026_06_15_000004_add_note_to_transactions.php.
    private function reinstallTransactionsTriggers(): void
    {
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
