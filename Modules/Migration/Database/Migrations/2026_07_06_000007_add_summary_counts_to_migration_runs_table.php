<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Persisted so a re-confirm can report the original counts without
        // re-promoting anything.
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
