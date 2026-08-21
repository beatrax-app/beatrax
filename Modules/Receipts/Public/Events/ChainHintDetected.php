<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Events;

use Modules\Receipts\Public\Enums\ChainHintType;

// Dispatched after a receipt-derived transaction lands with a
// structured cross-source clue; the Chains module subscribes and
// creates candidate chain_links rows eagerly. hintPayload is a typed
// sub-DTO so consumers deconstruct via instanceof, not array access.
final readonly class ChainHintDetected
{
    public function __construct(
        public int $sourceTransactionId,
        public ChainHintType $hintType,
        public object $hintPayload,
        public string $evidence,
        public int $userId,
    ) {}
}
