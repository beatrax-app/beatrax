<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto\ChainHintPayload;

use Spatie\LaravelData\Data;

/**
 * Structured payload for a `ChainHintDetected` event of type
 * `'refund_of'`. Emitted when a matcher extracts the original
 * reference id from a refund receipt body (e.g. Google Play: "Refund
 * for order GPA.1234-5678-9012-34567"). The Chains module listener
 * pairs the refund leg back to its original purchase via the
 * `originalReferenceId` lookup against `transactions.source_ref`.
 */
final class RefundOfPayload extends Data
{
    public function __construct(
        public readonly string $originalReferenceId,
    ) {}
}
