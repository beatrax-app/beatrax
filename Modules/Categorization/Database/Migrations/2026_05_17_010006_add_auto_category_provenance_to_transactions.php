<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds a nullable JSON `auto_category_provenance` column to
 * `transactions` so ApplyAutoCategoryStage can record the source of
 * an auto-categorisation alongside the existing `category_id`.
 *
 * Payload shape:
 *
 *   { "source": "rule" | "memory",
 *     "rule_id": int?,
 *     "memory_id": int?,
 *     "category_id": int }
 *
 * Read by the correction-divergence flow (toast + inline drawer
 * panel) so the user knows whether the suggestion came from an
 * explicit rule (which they may want to update) or from learned
 * merchant memory (which silently adjusts on the next correction).
 *
 * The column is NOT part of the v3 fingerprint tuple — adding it
 * does not bump the fingerprint version and does not require a
 * re-derive run. The column is nullable so transactions categorised
 * before the column existed keep working unchanged.
 */
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->json('auto_category_provenance')->nullable()->after('category_id');
        });
    }

    public function down(): void
    {
        $this->schema()->table('transactions', static function (Blueprint $table): void {
            $table->dropColumn('auto_category_provenance');
        });
    }
};
