<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Dto\ChainHintPayload;

use Spatie\LaravelData\Data;

// Emitted when a matcher extracts a card-ending anchor from the
// receipt body (e.g. "Paid with: Visa ending 1234"); the Chains
// listener uses the last-four + timestamp window to propose a
// candidate chain_links row against the matching card statement.
final class FundedByCardPayload extends Data
{
    public function __construct(
        public readonly string $cardLast4,
    ) {}
}
