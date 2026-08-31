<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PreviewRowStatus;

/**
 * @see FingerprintDisposition
 */
final class EnrichedDisposition extends FingerprintDisposition
{
    /**
     * @param  array<array-key, array{stored: mixed, incoming: mixed}>  $conflictingFields  Per-field disagreements detected during classify() (counterparty_name/description/currency/amount_minor vs. the stored row); ApplyEnrichments resolves each per the user's receipt_conflict_resolution policy. Empty by default (pure source_ref-only enrichment).
     */
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly ?string $fromSourceRef,
        public readonly string $toSourceRef,
        public readonly array $conflictingFields = [],
    ) {}

    public function status(): PreviewRowStatus
    {
        return PreviewRowStatus::Enriched;
    }
}
