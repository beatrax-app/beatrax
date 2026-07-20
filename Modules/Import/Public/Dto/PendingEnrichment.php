<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * @see EnrichedDisposition
 * @link ../../../../.docs/architecture/ingestion-pipeline.md#8-fingerprint-fingerprintstage
 */
final class PendingEnrichment extends Data
{
    /**
     * @param  array<string, array{stored: mixed, incoming: mixed}>  $conflictingFields  Per-field disagreements detected during classify(); ApplyEnrichments resolves each per the user's receipt_conflict_resolution policy. Empty by default (pure source_ref-only enrichment).
     */
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly string $newSourceRef,
        public readonly int $importRunId,
        public readonly string $sourceFormat,
        public readonly array $conflictingFields = [],
    ) {}
}
