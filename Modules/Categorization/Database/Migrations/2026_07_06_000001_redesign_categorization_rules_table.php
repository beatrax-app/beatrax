<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Redesigns `categorization_rules` from a flat single-condition/single-
 * action row into the parent half of the new normalized multi-condition/
 * multi-action rules engine (D-01, Req 5):
 *
 *   - DROPPED: `field`, `match`, `value`, `category_id` — these move to
 *     the new `rule_conditions` / `rule_actions` child tables (created
 *     by the 000002 / 000003 migrations that follow).
 *   - ADDED: `priority` (unsigned int, default 0) — evaluation order;
 *     `combinator` ('all' | 'any', default 'all') — how the rule's
 *     conditions combine.
 *   - KEPT: `active`, `notes`, `hits_count`, timestamps.
 *
 * CRITICAL (Req 5, forward migration): the flat condition/action data
 * on every existing row would be lost the instant `dropColumn()` runs.
 * Before dropping anything, this migration stashes a full copy of every
 * `categorization_rules` row into a temporary `_legacy_categorization_rules`
 * table. Migration 000005 (`migrate_existing_rules_to_conditions_actions`)
 * reads that stash to backfill `rule_conditions` + `rule_actions` and
 * drops the stash table once the backfill completes — by then the flat
 * columns no longer exist anywhere else, so the stash is the *only*
 * surviving source of the legacy data.
 *
 * CRITICAL (SQLite trigger hazard): SQLite rebuilds the entire table on
 * any column add/drop via `Blueprint::table()` and SILENTLY DROPS all
 * triggers in the process (see
 * 2026_06_15_000004_add_note_to_transactions.php for the canonical
 * writeup of this hazard against `transactions`). This migration must
 * therefore (re)install a paired `categorization_rules_combinator_check_*`
 * BEFORE INSERT / BEFORE UPDATE OF combinator trigger AFTER the
 * drop+add column change, using the same allow-list `RAISE(ABORT, ...)`
 * idiom as the original `field`/`match` triggers this migration retires.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // 1. Drop the old field/match enum-guard triggers. The table
        //    rebuild below would drop them anyway, but doing so
        //    explicitly documents intent and keeps this migration
        //    correct even if a future Laravel version stops rebuilding
        //    SQLite tables on simple column changes.
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_match_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_match_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_field_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_field_check_insert');

        // 2. Stash every existing flat rule row BEFORE the flat columns
        //    are dropped (Req 5 — the only surviving source of legacy
        //    condition/action data for migration 000005's backfill).
        $this->schema()->create('_legacy_categorization_rules', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('field', 16);
            $table->string('match', 16);
            $table->string('value');
            $table->unsignedBigInteger('category_id');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('hits_count')->default(0);
            $table->text('notes')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
        });

        $connection->statement(
            'INSERT INTO _legacy_categorization_rules '.
            '(id, user_id, field, match, value, category_id, active, hits_count, notes, created_at, updated_at) '.
            'SELECT id, user_id, field, match, value, category_id, active, hits_count, notes, created_at, updated_at '.
            'FROM categorization_rules',
        );

        // 3. Redesign the parent table shape. The original UNIQUE
        //    (user_id, field, match, value) index and the category_id
        //    foreign key must both be dropped FIRST — SQLite's native
        //    ALTER TABLE ... DROP COLUMN refuses to drop a column still
        //    referenced by an index or a foreign key constraint.
        $this->schema()->table('categorization_rules', static function (Blueprint $table): void {
            $table->dropUnique('categorization_rules_user_id_field_match_value_unique');
            $table->dropForeign(['category_id']);
            $table->dropColumn(['field', 'match', 'value', 'category_id']);
            $table->unsignedInteger('priority')->default(0);
            $table->string('combinator', 8)->default('all');
        });

        // 4. The table rebuild above dropped all indexes/triggers on
        //    categorization_rules. (Re)install the combinator enum-guard
        //    triggers.
        $allowedCombinators = "'all','any'";
        $connection->statement(sprintf(
            "CREATE TRIGGER categorization_rules_combinator_check_insert BEFORE INSERT ON categorization_rules FOR EACH ROW
             WHEN NEW.combinator NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.combinator value'); END",
            $allowedCombinators,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER categorization_rules_combinator_check_update BEFORE UPDATE OF combinator ON categorization_rules FOR EACH ROW
             WHEN NEW.combinator NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.combinator value'); END",
            $allowedCombinators,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_combinator_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_combinator_check_insert');

        // Reverse the shape change. This restores the ORIGINAL columns
        // verbatim (see 2026_05_17_010003_create_categorization_rules_table.php)
        // but does NOT restore data — by the time down() runs, the
        // `_legacy_categorization_rules` stash has already been consumed
        // and dropped by migration 000005's up(), so a full data
        // rollback is not possible. down() only restores the schema
        // shape, matching this migration's own "reverse the shape, not
        // the data" contract.
        $this->schema()->table('categorization_rules', static function (Blueprint $table): void {
            $table->dropColumn(['priority', 'combinator']);
            $table->string('field', 16);
            $table->string('match', 16);
            $table->string('value');
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unique(['user_id', 'field', 'match', 'value']);
        });

        // The table rebuild above dropped triggers again — reinstall
        // the original field/match enum-guard triggers verbatim.
        $allowedFields = "'merchant','description','counterparty'";
        $connection->statement(sprintf(
            "CREATE TRIGGER categorization_rules_field_check_insert BEFORE INSERT ON categorization_rules FOR EACH ROW
             WHEN NEW.field NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.field value'); END",
            $allowedFields,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER categorization_rules_field_check_update BEFORE UPDATE OF field ON categorization_rules FOR EACH ROW
             WHEN NEW.field NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.field value'); END",
            $allowedFields,
        ));

        $allowedMatches = "'contains','equals','starts_with'";
        $connection->statement(sprintf(
            "CREATE TRIGGER categorization_rules_match_check_insert BEFORE INSERT ON categorization_rules FOR EACH ROW
             WHEN NEW.match NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.match value'); END",
            $allowedMatches,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER categorization_rules_match_check_update BEFORE UPDATE OF match ON categorization_rules FOR EACH ROW
             WHEN NEW.match NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid categorization_rules.match value'); END",
            $allowedMatches,
        ));

        // Defensive cleanup — harmless no-op if migration 000005 already
        // dropped this table (the normal forward-migration flow).
        $this->schema()->dropIfExists('_legacy_categorization_rules');
    }
};
