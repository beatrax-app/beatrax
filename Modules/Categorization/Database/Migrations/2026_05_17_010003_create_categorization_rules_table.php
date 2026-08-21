<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// UNIQUE (user_id, field, match, value) blocks a duplicate rule at the
// database layer; (user_id, active) serves the once-per-transaction
// "which active rules does this user own?" lookup. hits_count is
// denormalised onto the rule for the /rules table.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('categorization_rules', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('field', 16);
            $table->string('match', 16);
            $table->string('value');
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unsignedInteger('hits_count')->default(0);
            $table->boolean('active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'field', 'match', 'value']);
            $table->index(['user_id', 'active']);
        });

        $connection = $this->db()->connection($this->getConnection());

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
    }

    public function down(): void
    {
        $connection = $this->db()->connection($this->getConnection());
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_match_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_match_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_field_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_field_check_insert');

        $this->schema()->dropIfExists('categorization_rules');
    }
};
