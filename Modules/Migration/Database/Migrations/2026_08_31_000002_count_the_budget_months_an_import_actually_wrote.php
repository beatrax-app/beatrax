<?php

declare(strict_types=1);

use Modules\Core\Database\Support\ModuleMigration;

// Every other figure on the results screen comes from what the promote step
// did. Budget months alone was counted from staging — what the file held —
// so a re-import that wrote nothing still reported "Imported 0 categories,
// 2 budget months, and 0 transactions". Storing it beside its siblings is
// what lets that screen read one source for all five.
return new class extends ModuleMigration
{
    public function up(): void
    {
        if (! $this->schema()->hasTable('migration_runs')) {
            return;
        }

        $this->schema()->table('migration_runs', function ($table): void {
            $table->unsignedInteger('budget_months_count')->default(0);
        });

        // A run already confirmed cannot say what it wrote — that number was
        // never kept. Its staged months are what its results screen has been
        // showing all along, so seeding them leaves old pages reading as they
        // did instead of newly claiming zero. Only new imports get the exact
        // figure.
        if (! $this->schema()->hasTable('migration_staging_budget_assignments')) {
            return;
        }

        $connection = $this->db()->connection($this->getConnection());

        $staged = $connection->table('migration_staging_budget_assignments')
            ->select('migration_run_id')
            ->selectRaw('COUNT(DISTINCT period_start) AS months')
            ->groupBy('migration_run_id')
            ->get();

        foreach ($staged as $row) {
            $connection->table('migration_runs')
                ->where('id', $row->migration_run_id)
                ->update(['budget_months_count' => (int) $row->months]);
        }
    }

    public function down(): void
    {
        if (! $this->schema()->hasTable('migration_runs')) {
            return;
        }

        $this->schema()->table('migration_runs', function ($table): void {
            $table->dropColumn('budget_months_count');
        });
    }
};
