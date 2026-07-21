<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto\ChainHintPayload;

use Spatie\LaravelData\Data;

// Emitted when a matcher extracts an original reference id from a
// refund receipt body; the Chains listener pairs the refund leg back
// to its original purchase via a transactions.source_ref lookup.
final class RefundOfPayload extends Data
{
    public function __construct(
        public readonly string $originalReferenceId,
    ) {}
}
