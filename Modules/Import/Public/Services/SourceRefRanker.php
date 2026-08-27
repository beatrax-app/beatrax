<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// One ranking, so FingerprintStage and ApplyEnrichments agree and the
// preview-then-confirm TOCTOU window closes.
final class SourceRefRanker
{
    // A stored receipt row carries its TRANSPORT format: the wizard arm and the
    // inbox job both normalise under 'eml'/'mbox' and leave the matcher key in
    // raw_payload. A matcher key is a different vocabulary and never reaches
    // either caller, both of which pass a source_format column.
    /**
     * @var list<string>
     */
    private const RECEIPT_FORMATS = [
        SourceFormat::Eml->value,
        SourceFormat::Mbox->value,
    ];

    public function isReceiptFormat(string $sourceFormat): bool
    {
        return in_array($sourceFormat, self::RECEIPT_FORMATS, true);
    }

    public function rank(?string $ref, string $format): int
    {
        if ($ref === null || $ref === '') {
            return 0;
        }

        return match ($format) {
            SourceFormat::Camt053->value => 4,
            SourceFormat::Mt940->value => 2,
            // A receipt's own reference beats the statement export's slug: it
            // carries the canonical PayPal Transaction ID where the CSV renders
            // an `O-...` slug, and a clean "Verkoper: <name>" where the ICS PDF
            // fuses the merchant with a city fragment.
            SourceFormat::Eml->value, SourceFormat::Mbox->value => 2,
            SourceFormat::IcsPdf->value => 1,
            CsvPresetRegistry::ASN => 1,
            // Same band as asn-csv: disjoint account_id values keep the two
            // from ever colliding under the fingerprint tuple.
            SourceFormat::PaypalCsv->value => 1,
            default => 0,
        };
    }
}
