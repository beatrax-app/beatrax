<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the categorization_rules table — user-defined matchers
 * that pre-categorise an incoming transaction at import time.
 *
 * Schema:
 *
 *   - id, user_id (nullable FK with cascade delete) — per-user scope.
 *   - field (16) — which transaction column to match: `merchant`,
 *     `description`, or `counterparty`.
 *   - match (16) — comparison operator: `contains`, `equals`,
 *     `starts_with`. All matches are case-insensitive at evaluation
 *     time.
 *   - value — the literal substring / prefix / equality target.
 *   - category_id (FK to categories with cascade delete) — the
 *     destination category when the rule fires.
 *   - hits_count — denormalised firing counter incremented by
 *     ApplyAutoCategoryStage; surfaces in the /rules table.
 *   - active (boolean, default true) — disable a rule without
 *     deleting it.
 *   - notes (text, nullable) — free-form memo also surfaced in the
 *     rule-form modal.
 *
 * UNIQUE (user_id, field, match, value) blocks duplicate rule
 * insertion at the database layer. Index (user_id, active) is the
 * RuleEvaluator hot-path: every transaction asks "which active rules
 * does this user own?" once per ingestion.
 *
 * The field + match enums are enforced via paired BEFORE INSERT /
 * BEFORE UPDATE triggers; a typo in the action layer fails loud at
 * the database boundary rather than landing a silently-broken rule.
 */
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
