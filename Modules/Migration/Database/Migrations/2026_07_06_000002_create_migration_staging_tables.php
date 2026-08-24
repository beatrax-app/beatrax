<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('migration_staging_categories', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            $table->string('source_external_id');
            $table->string('source_group_name')->nullable();
            $table->string('name');
            // Points at another row in this same staging table (this run's
            // category tree), not at the real categories table.
            $table->string('parent_source_external_id')->nullable();
            $table->string('kind');
            // 'mapped' | 'unmapped'
            $table->string('resolution_status')->default('unmapped');
            $table->unsignedBigInteger('resolved_category_id')->nullable();

            $table->index(['migration_run_id']);
        });

        $this->schema()->create('migration_staging_accounts', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            $table->string('source_external_id');
            $table->string('name');
            $table->string('kind');
            $table->char('currency', 3);
            $table->string('resolution_status')->default('unmapped');
            $table->unsignedBigInteger('resolved_account_id')->nullable();

            $table->index(['migration_run_id']);
        });

        $this->schema()->create('migration_staging_payees', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            // Nullable: some YNAB payees carry no stable source id; the
            // natural-key fallback lives on migration_source_map, not here.
            $table->string('source_external_id')->nullable();
            $table->string('normalized_name');
            $table->string('resolution_status')->default('unmapped');
            $table->unsignedBigInteger('resolved_counterparty_id')->nullable();

            $table->index(['migration_run_id']);
        });

        $this->schema()->create('migration_staging_budget_assignments', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            $table->string('source_category_external_id');
            $table->date('period_start');
            $table->bigInteger('budgeted_minor');
            $table->char('currency', 3);

            $table->index(['migration_run_id']);
        });

        $this->schema()->create('migration_staging_transactions', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            $table->string('source_external_id');
            $table->string('account_source_external_id');
            $table->dateTime('posted_at');
            $table->bigInteger('amount_minor');
            $table->char('currency', 3);
            $table->bigInteger('settled_amount_minor');
            $table->char('settled_currency', 3);
            $table->string('payee_source_external_id')->nullable();
            $table->text('description')->nullable();
            $table->string('cleared_status');
            $table->boolean('is_split_parent')->default(false);
            // The split parent in this same staging table: reconstructed
            // heuristically for YNAB, read from Actual's explicit parent_id.
            $table->string('parent_source_external_id')->nullable();
            $table->string('transfer_counterpart_source_external_id')->nullable();

            $table->index(['migration_run_id']);
        });

        $this->schema()->create('migration_staging_unmapped_items', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            // 'extra' | 'conflict'
            $table->string('item_type');
            $table->string('source_external_id')->nullable();
            $table->string('display_label');
            $table->text('reason')->nullable();

            $table->index(['migration_run_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_staging_unmapped_items');
        $this->schema()->dropIfExists('migration_staging_transactions');
        $this->schema()->dropIfExists('migration_staging_budget_assignments');
        $this->schema()->dropIfExists('migration_staging_payees');
        $this->schema()->dropIfExists('migration_staging_accounts');
        $this->schema()->dropIfExists('migration_staging_categories');
    }

    private function scopeColumns(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
    }
};
