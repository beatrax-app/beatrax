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
     * canonical row. The first-conflict toast flow (D-707) only
     * triggers when an enrichment is sourced from a receipt format;
     * a CSV-vs-MT940 enrichment is a Wave 1 silent ENRICHED path
     * with no user-visible conflict prompt.
     *
     * Centralising the list here means plan 05's CAT-04 surfacing +
     * future per-sender matchers share one source of truth for
     * "is this row a receipt?".
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
            'asn-camt053' => 4,
            'asn-mt940' => 2,
            // PayPal email receipts win on ENRICHED over their CSV
            // counterpart: a receipt carries the canonical PayPal
            // Transaction ID (17-char alphanumeric) while the CSV's
            // Transactiereferentie is the same identifier rendered as
            // an `O-...` slug. When both surface for the same logical
            // payment the receipt-derived reference is the audit-
            // friendly choice — ranking it ABOVE 'paypal-csv' makes
            // the FingerprintStage prefer the receipt at the cross-
            // format enrichment site.
            'paypal-receipt' => 2,
            // ICS receipts (email) beat ICS PDF (consumer-portal
            // statement) on ENRICHED for the merchant-name field: the
            // email-receipt anchor carries a clean "Verkoper: <name>"
            // value while the PDF row carries the same merchant fused
            // with a city + country fragment in the "Omschrijving"
            // column. Rank 2 places the receipt one above the PDF so
            // when both surface for the same logical card-charge the
            // receipt-derived reference (when present) wins.
            'ics-receipt' => 2,
            'ics-pdf' => 1,
            // Google Play receipts are standalone in v1 — Google Play
            // has no other ingestion path. Rank below paypal-receipt
            // (no cross-format dedup risk; the (account_id, currency,
            // amount) tuple keeps Google Play disjoint from ASN/ICS
            // rows under v3 fingerprint).
            'google-play-receipt' => 1,
            'asn-csv' => 1,
            // PayPal Activity Download CSV rides in the same band as
            // asn-csv. PayPal rows never collide with ASN rows under
            // the v3 fingerprint tuple due to disjoint account_id
            // values, so the equivalent rank is correct: cross-format
            // enrichment between PayPal and any ASN format is a
            // deliberate non-goal handled by the chain-resolver
            // module rather than by this ranker.
            'paypal-csv' => 1,
            default => 0,
        };
    }
}
