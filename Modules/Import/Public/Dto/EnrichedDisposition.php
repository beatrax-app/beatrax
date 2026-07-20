<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

/**
 * @see FingerprintDisposition
 */
final class EnrichedDisposition extends FingerprintDisposition
{
    /**
     * @param  array<string, array{stored: mixed, incoming: mixed}>  $conflictingFields  Per-field disagreements detected during classify() (counterparty_name/description/currency/amount_minor vs. the stored row); ApplyEnrichments resolves each per the user's receipt_conflict_resolution policy. Empty by default (pure source_ref-only enrichment).
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
