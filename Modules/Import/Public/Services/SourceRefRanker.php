<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

use Modules\Ingestion\Public\Enums\SourceFormat;

// One ranking, so FingerprintStage and ApplyEnrichments agree and the
// preview-then-confirm TOCTOU window closes.
final class SourceRefRanker
{
    /**
     * @var list<string>
     */
    private const RECEIPT_FORMATS = ['paypal-receipt', 'ics-receipt', 'google-play-receipt'];

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
            // Above paypal-csv: the receipt carries the canonical PayPal
            // Transaction ID where the CSV renders it as an `O-...` slug.
            'paypal-receipt' => 2,
            // Above ics-pdf: the receipt carries a clean "Verkoper: <name>"
            // where the PDF fuses the merchant with a city fragment.
            'ics-receipt' => 2,
            'ics-pdf' => 1,
            'google-play-receipt' => 1,
            SourceFormat::AsnCsv->value => 1,
            // Same band as asn-csv: disjoint account_id values keep the two
            // from ever colliding under the fingerprint tuple.
            'paypal-csv' => 1,
            default => 0,
        };
    }
}
