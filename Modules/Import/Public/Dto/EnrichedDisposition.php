<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Modules\Import\Public\Enums\PreviewRowStatus;
use Modules\Ledger\Public\Dto\TransactionBooking;

/**
 * @see FingerprintDisposition
 */
final class EnrichedDisposition extends FingerprintDisposition
{
    /**
     * @param  array<array-key, array{stored: mixed, incoming: mixed}>  $conflictingFields  Per-field disagreements detected during classify() (counterparty_name/description/currency/amount_minor vs. the stored row); ApplyEnrichments resolves each per the user's receipt_conflict_resolution policy. Empty by default (pure source_ref-only enrichment).
     * @param  TransactionBooking|null  $restates  The restating row's own booking terms, set only where the reference lookup matched a row the source filed on another day; ApplyEnrichments adopts them rather than putting them to the reader.
     */
    public function __construct(
        public readonly int $existingTransactionId,
        public readonly ?string $fromSourceRef,
        public readonly string $toSourceRef,
        public readonly array $conflictingFields = [],
        public readonly ?TransactionBooking $restates = null,
    ) {}

    public function status(): PreviewRowStatus
    {
        return PreviewRowStatus::Enriched;
    }
}
