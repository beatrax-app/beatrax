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
    public function rank(?string $ref, string $format): int
    {
        if ($ref === null || $ref === '') {
            return 0;
        }

        return match ($format) {
            'asn-camt053' => 4,
            'asn-mt940' => 2,
            'asn-csv' => 1,
            default => 0,
        };
    }
}
