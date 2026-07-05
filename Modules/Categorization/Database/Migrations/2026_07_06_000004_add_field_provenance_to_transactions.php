<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Adds a nullable JSON `field_provenance` column to `transactions`
 * (D-04) — a generic per-field manual-vs-rule-vs-import provenance map
 * consumed by the re-apply-rules manual-edit guard (Req 6): a field the
 * user has hand-edited must never be silently overwritten by a rule
 * re-application.
 *
 * Payload shape: `{ "<logical field>": "manual" | "rule" | "import" }`
 * — e.g. `{"category": "manual", "note": "rule"}`.
 *
 * COEXIST, not replace (RESEARCH.md Assumption A1, corrected recommendation):
 * this column is entirely separate from the existing
 * `auto_category_provenance` column added by
 * 2026_05_17_010006_add_auto_category_provenance_to_transactions.php.
 * `auto_category_provenance` keeps its existing
 * `{source, rule_id, memory_id, category_id}` shape and continues to
 * feed the `CategorizationDiverged` correction-divergence toast
 * unchanged. `field_provenance` is new and generic, feeding only the
 * re-apply manual-edit guard. Do NOT touch or drop
 * `auto_category_provenance` in this migration.
 *
 * CRITICAL: SQLite rebuilds the entire `transactions` table on any
 * column-add via `Blueprint::table()` and SILENTLY DROPS all triggers
 * in the process. This migration's timestamp (2026_07_06_000004) is
 * later than 2026_06_15_000004_add_note_to_transactions.php, making it
 * the new last-toucher of `transactions` — it now owns the trigger
 * re-install from this point forward. The four
 * transactions_*_check_* triggers below are reinstalled verbatim
 * (identical DDL) from that migration in BOTH up() and down().
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

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

    /**
     * Re-creates all four transactions_*_check_* triggers. Verbatim
     * copy of the DDL in
     * 2026_06_15_000004_add_note_to_transactions.php — column-add and
     * column-drop both rebuild the SQLite table and drop every trigger,
     * so both up() and down() must call this.
     */
    private function reinstallTransactionsTriggers(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // --- type triggers ---
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

        // --- payment_type triggers ---
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

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
