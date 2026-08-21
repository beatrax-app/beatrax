<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

// Gives the income cadence-flip fallback a precise seam: two payroll providers
// can share a normalised detected_name but differ by IBAN. Backfilling existing
// rows from detected_name is safe because the only cluster-key shape predating
// this column was already keyed on that same string.
return new class extends ModuleMigration
{
    public function up(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->string('cluster_counterparty_key')->nullable()->after('cluster_key');
            $table->index(
                ['user_id', 'direction', 'cluster_counterparty_key', 'latest_currency'],
                'rec_series_cluster_cp_key_idx',
            );
        });

        $this->db()->connection($this->getConnection())
            ->table('recurring_series')
            ->whereNull('cluster_counterparty_key')
            ->update([
                'cluster_counterparty_key' => $this->db()->connection($this->getConnection())->raw('detected_name'),
            ]);
    }

    public function down(): void
    {
        $this->schema()->table('recurring_series', static function (Blueprint $table): void {
            $table->dropIndex('rec_series_cluster_cp_key_idx');
            $table->dropColumn('cluster_counterparty_key');
        });
    }
};
