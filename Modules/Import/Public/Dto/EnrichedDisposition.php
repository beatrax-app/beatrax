<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

/**
 * FingerprintDisposition variant returned when an existing transactions
 * row matches the fingerprint but the incoming source_ref is stronger
 * than the stored one (an EndToEndId beats a transaction-reference, which
 * beats a row-index fallback). The Import pipeline updates the existing
 * row with the new source_ref and appends a provenance entry to
 * enriched_from rather than inserting a duplicate canonical.
 *
 * `conflictingFields` carries per-field disagreements detected during
 * classify(): when the incoming canonical row's counterparty_name /
 * description / currency / amount_minor differs from the stored row's
 * non-null value, the disagreement is recorded here. ImportPipeline
 * threads the map through into the PendingEnrichment; ApplyEnrichments
 * resolves the conflict per the user's receipt_conflict_resolution
 * policy at write time. Empty `[]` (the default) means a pure source_ref-
 * only enrichment with no field-value clash — the original Wave 1
 * behaviour stays unchanged for the no-conflict path.
 */
final class EnrichedDisposition extends FingerprintDisposition
{
    /**
     * @param  array<string, array{stored: mixed, incoming: mixed}>  $conflictingFields
     */
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly ?string $fromSourceRef,
        public readonly string $toSourceRef,
        public readonly array $conflictingFields = [],
    ) {}

    public function status(): string
    {
        return 'enriched';
    }
}
