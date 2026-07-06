<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Plan 06 Rule 2 fix: `ConfirmMigration`'s already-confirmed short-circuit
 * (mirrors `ConfirmImport`'s identical guard) must return a zero-action
 * result "built from persisted counts" without re-promoting — but
 * `migration_runs` (Plan 01) carried no columns to persist those counts on,
 * unlike `import_runs.inserted_count`/`duplicate_count`/`error_count`, which
 * `ConfirmImport` reads back on re-confirm. Mirrors that exact precedent.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('migration_runs', static function (Blueprint $table): void {
            $table->unsignedInteger('categories_count')->default(0);
            $table->unsignedInteger('accounts_count')->default(0);
            $table->unsignedInteger('transactions_inserted_count')->default(0);
            $table->unsignedInteger('transactions_skipped_count')->default(0);
            $table->unsignedInteger('splits_count')->default(0);
            $table->unsignedInteger('transfers_paired_count')->default(0);
            $table->unsignedInteger('counterparties_resolved_count')->default(0);
            $table->unsignedInteger('goals_created_count')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('migration_runs', static function (Blueprint $table): void {
            $table->dropColumn([
                'categories_count',
                'accounts_count',
                'transactions_inserted_count',
                'transactions_skipped_count',
                'splits_count',
                'transfers_paired_count',
                'counterparties_resolved_count',
                'goals_created_count',
            ]);
        });
    }
};
