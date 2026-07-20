<?php

declare(strict_types=1);

namespace Modules\Import\Public\Services;

/**
 * Canonical source-format strength ranking shared by every site that
 * needs to compare an incoming reference against a stored reference for
 * cross-format dedup.
 *
 * The rule is: rank a present reference by the format that produced it
 * (CAMT.053 > MT940 > CSV > anything else), and rank a NULL or empty
 * reference at zero so an absent reference never beats a present one.
 *
 * Centralising the rank lets the preview-time classifier
 * (FingerprintStage) and the write-time enrichment applier
 * (ApplyEnrichments) agree on the ordering, which is what closes the
 * preview-then-confirm TOCTOU window where a parallel run could already
 * have stored a stronger ref between the two phases.
 */
final class SourceRefRanker
{
    /**
     * Source-format slugs that represent an email-receipt-derived
     * canonical row. The first-conflict toast flow only triggers when
     * an enrichment is sourced from a receipt format; a CSV-vs-MT940
     * enrichment is a silent ENRICHED path with no user-visible
     * conflict prompt.
     *
     * Centralising the list here means any future per-sender matchers
     * share one source of truth for "is this row a receipt?".
     *
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
