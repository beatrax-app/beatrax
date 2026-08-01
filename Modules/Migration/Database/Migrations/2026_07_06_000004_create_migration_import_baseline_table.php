<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates `migration_import_baseline` (PERSISTENT, sibling to
 * `migration_source_map` — D-11): the third leg of the 3-way merge D-12
 * concretizes. Per mapped entity + field, stores the source-provided value
 * at the time it was LAST imported/applied, so a later re-import can compare
 * `{new source value}` vs `{this stored baseline}` vs `{current beatrax
 * value}` and decide skip / apply / conflict (Req 10):
 *
 *   source unchanged since baseline           -> skip
 *   source changed, beatrax == baseline       -> apply, advance baseline
 *   source changed AND beatrax != baseline    -> conflict, apply nothing
 *
 * `baseline_value` is a plain text column (JSON for composite fields such as
 * a transaction's amount+currency pair, a bare scalar string otherwise) —
 * deliberately untyped so one table serves every entity kind's differing
 * field shapes without a column per possible field.
 *
 * One row per (migration_source_map_id, field_name) — a mapped entity with
 * several independently-reconcilable fields (e.g. a transaction's amount,
 * date, and payee) gets one baseline row per field, not one row for the
 * whole entity, so per-item AND per-field conflict granularity is possible
 * (D-13: conflicts are per-item, not per-import).
 *
 * This table IS registered in `MergeRulesRegistry` alongside
 * `migration_source_map` (Open Question 5, resolved: register both
 * persistent tables).
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('migration_import_baseline', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->foreignId('migration_source_map_id')->constrained('migration_source_map')->cascadeOnDelete();
            $table->string('field_name');
            $table->text('baseline_value');
            $table->timestamp('imported_at');

            $table->index(['migration_source_map_id', 'field_name']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_import_baseline');
    }
};
