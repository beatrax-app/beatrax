<?php

declare(strict_types=1);

namespace Modules\Ledger\Internal\Support;

// The row set a one-shot pass answered for. Order-free on purpose: a row
// replicated from a paired device is inserted under THAT device's id, so it
// can land anywhere in the range and a highest-id watermark steps over it.
/**
 * @link ../../../../.docs/features/ingestion/asn-description-delimiters.md#why-it-is-not-a-highest-id-watermark
 */
final readonly class SweptRowSummary
{
    public function __construct(
        public int $rows,
        public int $idSum,
    ) {}

    public function isEmpty(): bool
    {
        return $this->rows === 0;
    }

    // Count alone misses a merge that deleted one row and created another;
    // the id sum moves for every set change but the one that swaps ids
    // adding to the same total.
    public function equals(self $other): bool
    {
        return $this->rows === $other->rows && $this->idSum === $other->idSum;
    }
}
