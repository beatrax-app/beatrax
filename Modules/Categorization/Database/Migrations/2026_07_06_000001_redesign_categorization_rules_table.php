<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// SQLite rebuilds the whole table on any column add/drop and silently drops
// every trigger with it, so both up() and down() reinstall their enum guards
// after the shape change rather than before.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $connection = $this->db()->connection($this->getConnection());

        // The rebuild below drops these anyway; going explicit keeps the
        // migration correct if Laravel ever stops rebuilding SQLite tables.
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_match_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_match_check_insert');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_field_check_update');
        $connection->statement('DROP TRIGGER IF EXISTS categorization_rules_field_check_insert');

        // Stash the flat rows before their columns go: this table is the only
        // surviving source for migration 000005's condition/action backfill.
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

        // The UNIQUE index and the category_id foreign key must be dropped
        // first: SQLite refuses to drop a column an index or a constraint
        // still references.
        $this->schema()->table('categorization_rules', static function (Blueprint $table): void {
            $table->dropUnique('categorization_rules_user_id_field_match_value_unique');
            $table->dropForeign(['category_id']);
            $table->dropColumn(['field', 'match', 'value', 'category_id']);
            $table->unsignedInteger('priority')->default(0);
            $table->string('combinator', 8)->default('all');
        });

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

        // Shape only, not data: migration 000005's up() has already consumed
        // and dropped the stash, so the flat rows cannot be rebuilt.
        $this->schema()->table('categorization_rules', static function (Blueprint $table): void {
            $table->dropColumn(['priority', 'combinator']);
            $table->string('field', 16);
            $table->string('match', 16);
            $table->string('value');
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unique(['user_id', 'field', 'match', 'value']);
        });

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

        // A no-op in the normal flow, where 000005 already dropped the stash.
        $this->schema()->dropIfExists('_legacy_categorization_rules');
    }
};
