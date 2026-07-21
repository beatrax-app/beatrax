<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Events;

// Dispatched when ApplyEnrichments detects a true field-value conflict
// while the user's receipt_conflict_resolution setting is still
// 'unset'; the toast listener surfaces the one-time choice and
// persists the policy for future conflicts.
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
