<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto\ChainHintPayload;

use Spatie\LaravelData\Data;

/**
 * Structured payload for a `ChainHintDetected` event of type
 * `'funded_by_card'`. Emitted when a matcher extracts a card-ending
 * anchor from the receipt body (e.g. PayPal: "Paid with: Visa ending
 * 1234"). The Chains module listener uses the last-four digits +
 * timestamp window to propose a candidate chain_links row against
 * the matching ICS card statement.
 */
final class FundedByCardPayload extends Data
{
    public function __construct(
        public readonly string $cardLast4,
    ) {}
}
