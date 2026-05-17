<?php

declare(strict_types=1);

namespace Modules\Receipts\Public\Events;

/**
 * Dispatched after a receipt-derived transaction lands with a
 * structured cross-source clue that does not fit in the
 * `transactions.reference_id` field alone.
 *
 * The Chains module subscribes to this event and creates candidate
 * `chain_links` rows eagerly — e.g. a `'funded_by_card'` hint with
 * card last-four "1234" proposes a chain_link between the receipt
 * transaction and the matching ICS card statement line.
 *
 * `hintPayload` is a typed sub-DTO under
 * `Modules\Receipts\Public\Dto\ChainHintPayload\…` so consumers
 * deconstruct it via `instanceof` rather than string-keyed array
 * access.
 *
 * Opt-in per matcher: matchers populate `ParsedReceiptDto::chainHints`
 * only when the anchor is actually present in the body. The common
 * case is no event.
 */
final readonly class ChainHintDetected
{
    public function __construct(
        public int $sourceTransactionId,
        public string $hintType,
        public object $hintPayload,
        public string $evidence,
        public int $userId,
    ) {}
}
