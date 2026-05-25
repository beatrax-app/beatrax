<?php

declare(strict_types=1);

namespace Modules\Import\Public\Enums;

/**
 * Closed enumeration of the bank CSV dialects the import pipeline can
 * be told to use. CSV is the only ambiguous bank-statement format —
 * every bank exports its own column shape — so the user picks the
 * source bank up front and the chosen case is what the pipeline
 * dispatches against instead of sniffing the file.
 *
 * The wizard's bank-connector step renders one chip per case in the
 * follow-on row that appears when the user picks the CSV format chip.
 * The enum value is the same string the SourceAdapterRegistry keys
 * its CSV adapters by — `adapterFormatKey()` returns it verbatim so
 * the dispatch path stays one lookup wide.
 */
enum BankCsvFormatHint: string
{
    case Asn = 'asn-csv';
    case Ing = 'ing-csv';

    /**
     * The format key the SourceAdapterRegistry keys this dialect's
     * adapter by. Used by ImportPipeline::preview() when dispatching
     * a CSV upload to the correct parser without re-sniffing the
     * file's content.
     */
    public function adapterFormatKey(): string
    {
        return $this->value;
    }

    /**
     * Short human-readable bank name rendered on the wizard's CSV
     * bank-picker chip and surfaced in the per-chip helper copy on
     * the bank-connector step.
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Asn => 'ASN',
            self::Ing => 'ING',
        };
    }
}
