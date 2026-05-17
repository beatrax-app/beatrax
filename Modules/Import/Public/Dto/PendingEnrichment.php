<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Buffered work item produced by the Import preview pipeline and replayed
 * by the enrichment writer during ConfirmImport. Each entry corresponds
 * to one existing transactions row that will be UPDATE-d with a stronger
 * source_ref and a new provenance entry appended to enriched_from.
 *
 * `conflictingFields` carries per-field disagreements detected at
 * fingerprint-classify time: when the incoming canonical row's
 * counterparty_name / description / currency / amount_minor differs
 * from the stored row's non-null value, FingerprintStage records the
 * disagreement here. ApplyEnrichments reads this map at write time
 * and (per the per-user receipt_conflict_resolution policy) either
 * applies the incoming value, keeps the stored value, or holds the
 * conflict in pending_enrichment_conflicts + dispatches
 * ReceiptConflictDetected so the user picks a policy.
 *
 * The map is keyed by canonical column name and carries both the
 * stored and incoming values side-by-side so the toast renderer can
 * show the user the exact disagreement. Empty `[]` (the default)
 * means a pure source_ref-only enrichment with no field-value clash.
 */
final class PendingEnrichment extends Data
{
    /**
     * @param  array<string, array{stored: mixed, incoming: mixed}>  $conflictingFields
     */
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly string $newSourceRef,
        public readonly int $importRunId,
        public readonly string $sourceFormat,
        public readonly array $conflictingFields = [],
    ) {}
}
