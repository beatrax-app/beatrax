<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the `rule_conditions` child table (D-02) — one row per
 * condition on a `categorization_rules` parent. A rule with `combinator
 * = 'all'` requires every condition to match; `combinator = 'any'`
 * requires at least one.
 *
 * Schema:
 *
 *   - id, rule_id (FK -> categorization_rules, cascade delete).
 *   - field (16) — which transaction property to compare. Meaningful
 *     only when `value_type = 'string'` (merchant/description/
 *     counterparty); `amount`/`date` value_types always compare
 *     against the transaction's canonical settled-amount / posted-date
 *     property and do not need a distinct field selector (see
 *     13.4-RESEARCH.md Open Question #1, resolved).
 *   - op (16) — comparison operator. String ops: contains, equals,
 *     starts_with. Amount ops: >, <, between. Date ops: before, after
 *     (between reuses the same amount-style two-value shape via
 *     `value`/`value2`).
 *   - value_type (8) — string | amount | date — which comparison
 *     branch RuleEngine dispatches to.
 *   - value — the primary comparison operand (always stored as a
 *     string; the engine parses it per value_type).
 *   - value2 (nullable) — the second operand for the `between` op.
 *
 * The field / op / value_type enums are enforced via paired BEFORE
 * INSERT / BEFORE UPDATE triggers — mirrors the
 * categorization_rules_field_check_* precedent from
 * 2026_05_17_010003_create_categorization_rules_table.php so a typo in
 * the action layer fails loud at the database boundary.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('rule_conditions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('categorization_rules')->cascadeOnDelete();
            $table->string('field', 16);
            $table->string('op', 16);
            $table->string('value_type', 8);
            $table->string('value');
            $table->string('value2')->nullable();
            $table->timestamps();

            $table->index(['rule_id']);
        });

        $connection = $this->db()->connection($this->getConnection());

        $allowedValueTypes = "'string','amount','date'";
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_conditions_value_type_check_insert BEFORE INSERT ON rule_conditions FOR EACH ROW
             WHEN NEW.value_type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_conditions.value_type value'); END",
            $allowedValueTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_conditions_value_type_check_update BEFORE UPDATE OF value_type ON rule_conditions FOR EACH ROW
             WHEN NEW.value_type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_conditions.value_type value'); END",
            $allowedValueTypes,
        ));

        $allowedFields = "'merchant','description','counterparty'";
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_conditions_field_check_insert BEFORE INSERT ON rule_conditions FOR EACH ROW
             WHEN NEW.field NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_conditions.field value'); END",
            $allowedFields,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_conditions_field_check_update BEFORE UPDATE OF field ON rule_conditions FOR EACH ROW
             WHEN NEW.field NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_conditions.field value'); END",
            $allowedFields,
        ));

        $allowedOps = "'contains','equals','starts_with','>','<','between','before','after'";
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_conditions_op_check_insert BEFORE INSERT ON rule_conditions FOR EACH ROW
             WHEN NEW.op NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_conditions.op value'); END",
            $allowedOps,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_conditions_op_check_update BEFORE UPDATE OF op ON rule_conditions FOR EACH ROW
             WHEN NEW.op NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_conditions.op value'); END",
            $allowedOps,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement('DROP TRIGGER IF EXISTS rule_conditions_op_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS rule_conditions_op_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS rule_conditions_field_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS rule_conditions_field_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS rule_conditions_value_type_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS rule_conditions_value_type_check_insert');

        $this->schema()->dropIfExists('rule_conditions');
    }
};
