<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// `field` names the text property a `string` condition compares; `amount` and
// `date` conditions always read the canonical settled amount and posted date,
// so it is inert for them. `value2` is the second operand of `between`.
/**
 * @link ../../../../.docs/features/categorization/rule-evaluation-order.md
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
