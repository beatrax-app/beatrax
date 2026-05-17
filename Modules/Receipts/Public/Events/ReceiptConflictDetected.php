<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Events;

/**
 * Dispatched when `ApplyEnrichments` detects a true field-value
 * conflict between an existing transaction row and a newly-parsed
 * receipt while the user's `receipt_conflict_resolution` setting is
 * still `'unset'`.
 *
 * The toast listener surfaces a one-time choice: prefer the receipt's
 * value going forward, or keep the first-write value. The chosen
 * policy is persisted to `users.receipt_conflict_resolution`;
 * subsequent conflicts apply it automatically without further
 * prompts.
 *
 * `receiptValue` and `csvValue` are stringified for display in the
 * toast copy; the structured side-channel rows live in
 * `pending_enrichment_conflicts` until the user resolves the
 * setting.
 */
final readonly class ReceiptConflictDetected
{
    public function __construct(
        public int $transactionId,
        public int $userId,
        public string $field,
        public ?string $receiptValue,
        public ?string $csvValue,
        public ?int $importRunId,
    ) {}
}
