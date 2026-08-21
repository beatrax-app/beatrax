<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->create('migration_staging_goals', function (Blueprint $table): void {
            $table->id();
            $this->scopeColumns($table);
            $table->string('category_source_external_id');
            $table->string('name');
            $table->bigInteger('target_minor');
            $table->char('target_currency', 3);
            // Nullable because Actual's goal_def can omit a target date; the
            // promoter reports a dateless goal as an unmapped "extra" rather than
            // inventing one, since goals.target_date is NOT NULL.
            $table->date('target_date')->nullable();

            $table->index(['migration_run_id']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists('migration_staging_goals');
    }

    private function scopeColumns(Blueprint $table): void
    {
        $table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();
        $table->foreignId('migration_run_id')->constrained('migration_runs')->cascadeOnDelete();
    }
};
