<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Events;

// Dispatched whenever ApplyEnrichments detects a true field-value conflict,
// under every receipt_conflict_resolution policy. Named for the two sides of
// the wire rather than for receipt-versus-statement: a statement enriching a
// receipt-written row disagrees in the other direction and is the same event.
final readonly class ReceiptConflictDetected
{
    public function __construct(
        public int $transactionId,
        public int $userId,
        public string $field,
        public ?string $incomingValue,
        public ?string $storedValue,
        public ?int $importRunId,
    ) {}
}
