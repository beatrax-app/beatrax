<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// The opaque `payload` is shaped per type: category {category_id},
// counterparty {counterparty_id}, note {text, mode: set|append},
// tax_tag {deduction_category_id, year?}.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('rule_actions', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('rule_id')->constrained('categorization_rules')->cascadeOnDelete();
            $table->integer('position');
            $table->string('type', 16);
            $table->json('payload');
            $table->timestamps();

            $table->index(['rule_id']);
        });

        $connection = $this->db()->connection($this->getConnection());

        $allowedTypes = "'category','counterparty','note','tax_tag'";
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_actions_type_check_insert BEFORE INSERT ON rule_actions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_actions.type value'); END",
            $allowedTypes,
        ));
        $connection->statement(sprintf(
            "CREATE TRIGGER rule_actions_type_check_update BEFORE UPDATE OF type ON rule_actions FOR EACH ROW
             WHEN NEW.type NOT IN (%s)
             BEGIN SELECT RAISE(ABORT, 'Invalid rule_actions.type value'); END",
            $allowedTypes,
        ));
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        $connection->statement('DROP TRIGGER IF EXISTS rule_actions_type_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS rule_actions_type_check_insert');

        $this->schema()->dropIfExists('rule_actions');
    }
};
