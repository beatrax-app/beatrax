<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Modules\Core\Database\Support\ModuleMigration;

/**
 * Adds `cluster_counterparty_key` to `recurring_series`.
 *
 * The detector composes a stable counterparty identifier per cluster
 * (IBAN when present, normalized counterparty description otherwise).
 * Persisting that identifier on the row gives the income cadence-flip
 * fallback lookup a precise seam: when `cluster_key` (which encodes
 * the cadence band) misses, the fallback can match by the underlying
 * counterparty identity rather than the free-form detected_name —
 * which is not unique across two payroll providers that share a
 * normalised display string but differ by IBAN.
 *
 * Backfill: existing rows are populated from `detected_name`. That is
 * a safe approximation for the historic dataset because the only
 * cluster-key shape that pre-dates this column was already keyed on
 * the same detected_name string (the IBAN-keyed multi-employer case
 * had no fallback at write time).
 */
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
