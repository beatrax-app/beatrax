<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the envelope_settings table — one row per (user_id, category_id)
 * holding `overspend_mode` (D-03): 'reduce_to_budget' | 'carry_negative'.
 * The toggle's lifetime is the envelope, not any month, so it does not live
 * on assignment rows or on the shared `categories` table.
 *
 * `overspend_mode` is NOT NULL with NO DB-level default: the Sync
 * EnvelopeSettingsRegistryColumnsTest pins it as a genuinely required create
 * column (a DB-level `->default('reduce_to_budget')` would drop it out of
 * the NOT-NULL-without-default set that test's `_create_required` list
 * requires). The application-layer default lives in CarryoverQuery / D-12a
 * (an envelope with no settings row at all reads as 'reduce_to_budget'), so
 * every settings row that does exist is written with an explicit,
 * intentional mode.
 *
 * `user_id` is NOT NULL — same upsert-safety rationale as
 * envelope_assignments/category_budgets (RESEARCH.md Pitfall 4 / A3): a
 * nullable user_id would make the UNIQUE(user_id, category_id) upsert below
 * unenforceable. Do NOT register this table in UserIdColumnArchTest.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('envelope_settings', static function (Blueprint $table): void {
            $table->id();
            // NOT NULL — see class doc / Pitfall 4.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            // NOT NULL, no DB-level default — see class doc.
            $table->string('overspend_mode', 32);
            $table->timestamps();

            $table->unique(['user_id', 'category_id'], 'envelope_settings_user_cat_uniq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('envelope_settings');
    }
};
