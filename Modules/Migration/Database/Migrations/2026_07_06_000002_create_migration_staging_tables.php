<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Creates the six `migration_staging_*` scratch tables (D-06/D-07): the
 * relational, no-destructive-write preview state a parsed import lands in
 * before `confirm` promotes it to the real domain tables via the public
 * writers (`EnvelopeWriter::setAssigned`, `RecordTransactions`,
 * `SaveTransactionSplit`, `ResolvesCounterparties`, `PairsTransferLegs`,
 * `GoalWriter`). Every row here is scoped to one `migration_run_id` (FK,
 * cascade-deletes with the run) and is safe to truncate wholesale on
 * discard/abandon without ever touching a domain table (Req 11).
 *
 * None of these six tables are registered in `MergeRulesRegistry` — they are
 * per-device scratch, never a sync surface (see the persistent
 * `migration_source_map`/`migration_import_baseline` tables in the sibling
 * migrations for the two tables that ARE registered).
 *
 * All monetary columns are `bigInteger` (never float, `NoFloatMoneyArchTest`)
 * paired with a `char(3)` currency column (ADR-0009). `user_id` is a
 * denormalized nullable copy alongside `migration_run_id` per the
 * project-wide nullable-user_id convention (ADR-0008).
 */
return new class extends Migration
{
    private ?DatabaseManager $resolvedDb = null;

    public function up(): void
    {
        $this->schema()->create('migration_staging_categories', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            $table->string('source_external_id');
            $table->string('source_group_name')->nullable();
            $table->string('name');
            // Self-referential to another row in this SAME staging table
            // (this run's category tree), not to the real categories table.
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
            // Nullable: some YNAB payees have no stable source id (D-10
            // natural-key fallback applies at the source_map layer, not here).
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
            // Self-referential to another row in THIS staging table (the
            // split parent), reconstructed heuristically for YNAB4/nYNAB
            // (Pitfall 2) or read directly from Actual's explicit parent_id.
            $table->string('parent_source_external_id')->nullable();
            // The other leg of a transfer pair, resolved at promote-time via
            // Transfers\PairsTransferLegs.
            $table->string('transfer_counterpart_source_external_id')->nullable();

            $table->index(['migration_run_id']);
        });

        $this->schema()->create('migration_staging_unmapped_items', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            // 'category' | 'payee' | 'extra' | 'conflict'
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

    /**
     * Every staging table shares the same scope pair: a nullable denormalized
     * `user_id` (ADR-0008) plus the required `migration_run_id` FK that scopes
     * the row to one run and cascade-deletes with it.
     */
    private function scopeColumns(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
    }

    private function schema(): Builder
    {
        return $this->db()->connection($this->getConnection())->getSchemaBuilder();
    }

    private function db(): DatabaseManager
    {
        if ($this->resolvedDb === null) {
            /** @var DatabaseManager $db */
            $db = Container::getInstance()->make(DatabaseManager::class);
            $this->resolvedDb = $db;
        }

        return $this->resolvedDb;
    }
};
