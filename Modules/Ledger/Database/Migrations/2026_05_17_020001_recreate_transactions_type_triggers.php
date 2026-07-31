<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Re-install the BEFORE INSERT / BEFORE UPDATE triggers on
     * transactions.type after every subsequent ALTER pass dropped them.
     *
     * SQLite's blueprint compiler recreates a table whenever a column is
     * added via Schema::table — the rename pass does not carry triggers
     * across to the new table. Every later transactions migration
     * (add_enriched_from, add_raw_payload, add_pair_transaction_id,
     * add_auto_category_provenance) therefore silently dropped the
     * paired type-check triggers created in
     * 2026_05_12_010005_create_transactions_table.php. The runtime
     * effect was that bad transaction-type values landed in the
     * database without being rejected — verified by
     * `Modules/Ledger/tests/Unit/TransactionTypeTest::it rejects an
     * invalid transaction type at the DB layer` failing post-Phase 6.
     *
     * Pinning the trigger creation to a migration that runs LAST
     * (later timestamp than every ALTER) gives the invariant a stable
     * landing zone. Future ALTERs on transactions must either be added
     * before this migration's timestamp, or re-install the triggers
     * themselves immediately after.
     *
     * The trigger DDL is identical to the original definition in the
     * create migration; allowed type list must stay in sync with
     * `Modules\\Ledger\\Public\\Enums\\TransactionType`.
     */
    public function up(): void
    {
        // DROP first so the migration is idempotent across re-runs and
        // across environments that may have already retained a stale
        // trigger definition.
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_update');

        $allowedTypes = "'expense','income','transfer_out','transfer_in','fee','refund','adjustment'";
        DB::statement(sprintf(
            "CREATE TRIGGER transactions_type_check_insert BEFORE INSERT ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
        DB::statement(sprintf(
            "CREATE TRIGGER transactions_type_check_update BEFORE UPDATE OF type ON transactions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid transactions.type value'); END",
            $allowedTypes,
        ));
    }

    public function down(): void
    {
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_insert');
        DB::statement('DROP TRIGGER IF EXISTS transactions_type_check_update');
    }
};
