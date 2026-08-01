<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Creates the envelope_assignments table — one mutable snapshot row per
 * (user_id, category_id, period_start) holding the assigned amount for that
 * envelope in that period (D-01).
 *
 * Money follows the project-wide signed BIGINT minor-units convention.
 * `currency` carries NO DB-level default and is NOT NULL: unlike
 * category_budgets (single-period), this table's `_create_required` sync
 * registry entry (Plan 05) pins `currency` as a genuinely required create
 * column (Sync Envelope*RegistryColumnsTest) — v1 is EUR-only (D-25) so
 * EnvelopeWriter (Plan 04) always supplies it explicitly rather than relying
 * on an implicit DB default. The UNIQUE (user_id, category_id, period_start)
 * constraint makes "set assigned" an idempotent upsert per period.
 *
 * `user_id` is NOT NULL (mirrors category_budgets, not pot_movements): a
 * nullable user_id would make the unique upsert below unenforceable, since
 * NULL is distinct in a unique index (SQLite/MySQL NULL-distinctness,
 * RESEARCH.md Pitfall 4 / A3). Do NOT register this table in
 * UserIdColumnArchTest.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('envelope_assignments', static function (Blueprint $table): void {
            $table->id();
            // NOT NULL — see class doc / Pitfall 4: a nullable user_id would
            // make the UNIQUE(user_id, category_id, period_start) upsert
            // below unenforceable.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->date('period_start'); // D-04 month key
            $table->bigInteger('assigned_minor'); // always >= 0 in practice; D-06 zero => row deleted
            // NOT NULL, no DB-level default — see class doc.
            $table->string('currency', 3);
            $table->timestamps();

            $table->unique(['user_id', 'category_id', 'period_start'], 'envelope_assignments_user_cat_period_uniq');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('envelope_assignments');
    }
};
