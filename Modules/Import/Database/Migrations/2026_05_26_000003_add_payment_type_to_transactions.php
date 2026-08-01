<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds the `payment_type` column to `transactions` — the closed
 * 8-value enum (pin / online / transfer / direct_debit / cash / fee /
 * refund / unknown) classified by the import pipeline's
 * PaymentTypeClassifierStage and surfaced as the leading Type chip on
 * the import preview row and the `/transactions` list.
 *
 * The column defaults to `unknown` so every existing row carries a
 * valid value the moment the column lands; the explicit backfill
 * statement below is a defensive guard against any historical row
 * landing on NULL before the trigger pair forbids it. The
 * `(user_id, payment_type)` composite index supports the chip-filter
 * hot path on the `/transactions` list.
 *
 * The allowed-value set is enforced by paired BEFORE INSERT / BEFORE
 * UPDATE OF payment_type triggers. SQLite cannot ALTER TABLE ADD CHECK
 * after the fact, so the trigger pair is the only schema-level
 * enforcement path. The triggers fire for every write path — Eloquent
 * create/save AND raw insertOrIgnore — so a single application-layer
 * bypass cannot land a bad value.
 *
 * SQLite's blueprint compiler rebuilds the table on column-add, which
 * drops the `transactions_type_check_*` trigger pair sitting on the
 * `type` column. This migration re-installs both trigger pairs at the
 * end of `up()` so the `type` and `payment_type` invariants stay
 * enforced after the column add.
 */
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

        // Defensive backfill: the column default takes care of every
        // existing row, but an explicit UPDATE before the trigger pair
        // lands guarantees no NULL survives into a trigger that would
        // reject it.
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

        // Re-install the transactions.type trigger pair: SQLite's
        // blueprint compiler can rebuild the transactions table on
        // column add, which silently drops triggers attached to the
        // previous incarnation. The allowed-type list must stay in
        // sync with `Modules\Ledger\Public\Enums\TransactionType`.
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
