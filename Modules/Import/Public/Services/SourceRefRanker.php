<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

// Ranks a present reference by the format that produced it (CAMT.053 >
// MT940 > CSV > anything else); NULL/empty ranks zero. Centralising
// this lets FingerprintStage and ApplyEnrichments agree on the
// ordering, closing the preview-then-confirm TOCTOU window.
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
            'camt053' => 4,
            'mt940' => 2,
            // PayPal email receipts win on ENRICHED over their CSV
            // counterpart: the receipt carries the canonical PayPal
            // Transaction ID while the CSV renders the same identifier
            // as an `O-...` slug — ranked above 'paypal-csv' so it wins.
            'paypal-receipt' => 2,
            // ICS receipts beat ICS PDF on ENRICHED for merchant-name:
            // the receipt carries a clean "Verkoper: <name>" value while
            // the PDF fuses the merchant with a city/country fragment —
            // rank one above the PDF so the receipt wins when present.
            'ics-receipt' => 2,
            'ics-pdf' => 1,
            // Google Play receipts are standalone — no other ingestion
            // path exists, so there is no cross-format dedup risk; the
            // (account_id, currency, amount) tuple already keeps them
            // disjoint from ASN/ICS rows under the fingerprint.
            'google-play-receipt' => 1,
            'asn-csv' => 1,
            // PayPal Activity Download CSV rides in the same band as
            // asn-csv — disjoint account_id values mean the two never
            // collide under the fingerprint tuple, so cross-format
            // enrichment between them is a non-goal for this ranker.
            'paypal-csv' => 1,
            default => 0,
        };
    }
}
