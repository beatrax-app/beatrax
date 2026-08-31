<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Ingestion\Public\Enums\SourceFormat;
use Modules\Ingestion\Public\Services\CsvPresetRegistry;

// One ranking, so FingerprintStage and ApplyEnrichments agree and the
// preview-then-confirm TOCTOU window closes.
final class SourceRefRanker
{
    // A receipt's own reference beats the statement export's slug: it carries
    // the canonical PayPal Transaction ID where the CSV renders an `O-...`
    // slug, and a clean "Verkoper: <name>" where the ICS PDF fuses the merchant
    // with a city fragment.
    private const int RECEIPT_RANK = 2;

    // Which formats those are is SourceFormat's answer, not a second list here:
    // a copy that omitted one broke every receipt once already. A matcher key
    // is a different vocabulary and reaches neither caller, both of which pass
    // a stored source_format column.
    public function isReceiptFormat(string $sourceFormat): bool
    {
        return SourceFormat::tryFrom($sourceFormat)?->isReceiptFile() === true;
    }

    public function rank(?string $ref, string $format): int
    {
        if ($ref === null || $ref === '') {
            return 0;
        }

        if ($this->isReceiptFormat($format)) {
            return self::RECEIPT_RANK;
        }

        return match ($format) {
            SourceFormat::Camt053->value => 4,
            SourceFormat::Mt940->value => 2,
            SourceFormat::IcsPdf->value => 1,
            CsvPresetRegistry::ASN => 1,
            // Same band as asn-csv: disjoint account_id values keep the two
            // from ever colliding under the fingerprint tuple.
            SourceFormat::PaypalCsv->value => 1,
            default => 0,
        };
    }
}
