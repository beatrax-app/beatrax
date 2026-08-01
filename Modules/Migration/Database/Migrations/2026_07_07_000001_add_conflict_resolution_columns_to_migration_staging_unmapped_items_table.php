<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * UAT gap-fix (13.5-HUMAN-UAT.md Test 3c): the "Keep local" / "Take source"
 * conflict toggle was a cosmetic no-op — `CheckForUpdates` committed the
 * keep-local decision synchronously, before the preview page ever rendered,
 * so there was nothing left for the toggle to actually change. Making the
 * decision real requires DEFERRING it from "conflict detected" time to
 * "user confirms" time, which means the conflict row itself must carry
 * enough to apply EITHER outcome later, not just a human-readable summary.
 *
 * Reuses `migration_staging_unmapped_items` (the existing conflict staging
 * store, `item_type = 'conflict'`) rather than a dedicated table: every
 * conflict already lives here, `PreviewSummaryBuilder` already reads from
 * here, and `ConfirmMigration`'s skip-list already queries here — adding
 * columns keeps one read/write path instead of introducing a second table
 * that would need its own FK/cascade-delete wiring and its own join back to
 * this one for the surrounding case (category/payee/extra) context.
 *
 * All seven columns are nullable: only `item_type = 'conflict'` rows ever
 * populate them (a category/payee/extra row's identity is fully carried by
 * the existing `source_external_id`/`display_label`/`reason` columns and
 * needs no apply-later data).
 *
 *   entity_type    'budget_assignment'|'category'|'account'|'transaction'
 *   field_name     the conflicting column, e.g. 'budgeted_minor'|'name'|
 *                  'description'|'amount_minor'
 *   local_value    the beatrax-side value at conflict-detection time,
 *                  stringified (round-trippable, NOT display-formatted)
 *   source_value   the newer export's value, stringified
 *   baseline_value the value both legs last agreed on, stringified
 *   currency       ISO 4217 code when the field is money-shaped, else NULL
 *   resolution     NULL (default keep-local) | 'keep_local' | 'take_source'
 *                  — NULL and 'keep_local' are equivalent; NULL simply means
 *                  the user has not touched the toggle yet.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('migration_staging_unmapped_items', static function (Blueprint $table): void {
            $table->string('entity_type')->nullable()->after('source_external_id');
            $table->string('field_name')->nullable()->after('entity_type');
            $table->text('local_value')->nullable()->after('field_name');
            $table->text('source_value')->nullable()->after('local_value');
            $table->text('baseline_value')->nullable()->after('source_value');
            $table->char('currency', 3)->nullable()->after('baseline_value');
            $table->string('resolution')->nullable()->after('currency');
        });
    }

    public function down(): void
    {
        $this->schema()->table('migration_staging_unmapped_items', static function (Blueprint $table): void {
            $table->dropColumn(['entity_type', 'field_name', 'local_value', 'source_value', 'baseline_value', 'currency', 'resolution']);
        });
    }
};
