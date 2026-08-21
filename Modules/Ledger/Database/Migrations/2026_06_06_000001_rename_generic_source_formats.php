<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Ledger\Public\Services\FingerprintComposer;

// source_format is persisted on every imported row, so the code rename has to
// move the data with it. asn-csv stays as it is: that one really does name a
// single bank's CSV layout. The fingerprint does not hash source_format, so
// this cannot resurface duplicates on a later re-import.
/**
 * @see FingerprintComposer
 */
return new class extends Migration
{
    /** @var array<string, string> old identifier => new identifier */
    private const RENAMES = [
        'asn-camt053' => 'camt053',
        'asn-mt940' => 'mt940',
    ];

    /** @var array<string, string> table => source-format column */
    private const COLUMNS = [
        'import_runs' => 'source_format',
        'transactions' => 'source_format',
        'pending_enrichment_conflicts' => 'incoming_source_format',
    ];

    public function up(): void
    {
        $this->apply(self::RENAMES);
    }

    public function down(): void
    {
        $this->apply(array_flip(self::RENAMES));
    }

    /**
     * @param  array<string, string>  $map
     */
    private function apply(array $map): void
    {
        foreach (self::COLUMNS as $table => $column) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($map as $from => $to) {
                DB::table($table)
                    ->where($column, $from)
                    ->update([$column => $to]);
            }
        }
    }
};
