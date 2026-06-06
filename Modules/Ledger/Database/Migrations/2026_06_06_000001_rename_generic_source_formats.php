<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Ledger\Public\Services\FingerprintComposer;

/**
 * Renames the two generic statement-format identifiers from their old
 * ASN-prefixed spelling to their bank-agnostic spelling, now that the
 * CAMT.053 (ISO 20022) and MT940 (SWIFT) parsers are no longer treated as
 * ASN-specific:
 *
 *   asn-camt053 -> camt053
 *   asn-mt940   -> mt940
 *
 * `source_format` is the wire identifier persisted on every imported row, so
 * existing data must be migrated in lock-step with the code rename. The
 * ASN-specific CSV format (`asn-csv`) is intentionally left untouched — it
 * describes ASN's particular CSV column layout, which is one bank's format
 * among many, not a generic standard.
 *
 * The transaction fingerprint does NOT include source_format (it hashes
 * user/account/dates/amount/currency/counterparty), so this rename cannot
 * create or resurface duplicates on a subsequent re-import.
 *
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
