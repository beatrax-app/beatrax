<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// SQLite cannot ALTER TABLE ADD CHECK after the fact, so the paired
// BEFORE INSERT / BEFORE UPDATE triggers below are the only schema-level
// guard on the allowed value set.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $schema = $this->schema();
        $connection = $this->db()->connection($this->getConnection());

        if (! $schema->hasColumn('transactions', 'payment_type')) {
            $schema->table('transactions', static function (Blueprint $table): void {
                $table->string('payment_type', 16)
                    ->default('unknown')
                    ->after('description');
            });
        }

        if (! $this->indexExists('transactions', 'transactions_user_id_payment_type_index')) {
            $schema->table('transactions', static function (Blueprint $table): void {
                $table->index(['user_id', 'payment_type']);
            });
        }

        // No NULL may survive into the trigger pair installed below.
        $connection->update("UPDATE transactions SET payment_type = 'unknown' WHERE payment_type IS NULL");

        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_update');
        $connection->statement(<<<'SQL'
            CREATE TRIGGER transactions_payment_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
            WHEN NEW.payment_type NOT IN ('pin','online','transfer','direct_debit','cash','fee','refund','unknown')
            BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END
        SQL);
        $connection->statement(<<<'SQL'
            CREATE TRIGGER transactions_payment_type_check_update BEFORE UPDATE OF payment_type ON transactions FOR EACH ROW
            WHEN NEW.payment_type NOT IN ('pin','online','transfer','direct_debit','cash','fee','refund','unknown')
            BEGIN SELECT RAISE(ABORT, 'Invalid transactions.payment_type value'); END
        SQL);

        // SQLite's blueprint compiler rebuilds the table on column add and
        // silently drops the triggers on the old one, so the type pair has to
        // be re-installed. Keep the list in sync with TransactionType.
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
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS transactions_payment_type_check_update');

        $schema = $this->schema();
        if ($this->indexExists('transactions', 'transactions_user_id_payment_type_index')) {
            $schema->table('transactions', static function (Blueprint $table): void {
                $table->dropIndex('transactions_user_id_payment_type_index');
            });
        }

        if ($schema->hasColumn('transactions', 'payment_type')) {
            $schema->table('transactions', static function (Blueprint $table): void {
                $table->dropColumn('payment_type');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = $this->db()->connection($this->getConnection());
        $row = $connection->selectOne(
            "SELECT name FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND name = ?",
            [$table, $indexName],
        );

        return $row !== null;
    }
};
