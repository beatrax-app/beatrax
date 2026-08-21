<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// SQLite rebuilds the whole table on a column add and silently drops every
// trigger with it, so all four transactions_*_check_* triggers are recreated
// here. This migration is now the last toucher of transactions: any later
// ALTER has to re-install them too.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->text('note')->nullable()->after('description');
        });

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

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('note');
        });

        // Dropping the column rebuilds the table, so the triggers go again.
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
