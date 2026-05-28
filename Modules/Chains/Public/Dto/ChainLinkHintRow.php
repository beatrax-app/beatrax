<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Single row payload for the `/chains/hints` queue. One instance per
 * `chain_links` row whose `to_transaction_id IS NULL` — the schema-
 * permitted hint shape used by upstream matchers when the partner
 * row may not yet exist (or, for exceeded-tolerance ics_bulk_settle
 * candidates, when the partner that DOES exist falls outside the
 * resolver's tolerance window).
 *
 * The DTO carries the "from" side display data + a human-readable
 * summary of `evidence` so the blade view can show what made the
 * matcher emit the hint, without re-querying the source row or
 * re-decoding the evidence JSON in the template.
 *
 * Hints have no "to" side by definition; the queue's only user
 * actions are Dismiss (hard-delete via DismissChainLinkHint) and
 * Open Source (drill into the from-transaction). A future
 * hint-resolution flow that lets the user attach a concrete
 * partner can extend this DTO with the candidate-picker context.
 */
final class ChainLinkHintRow extends Data
{
    /**
     * @param  list<string>  $evidenceLines  Human-readable bullet
     *                                       points derived from the
     *                                       hint's `evidence` JSON.
     *                                       Empty when evidence is
     *                                       absent or unparseable —
     *                                       the row still renders.
     */
    public function __construct(
        public readonly int $chainLinkId,
        public readonly string $kind,
        public readonly float $confidence,
        public readonly int $fromTransactionId,
        public readonly string $fromCounterparty,
        public readonly Money $fromAmount,
        public readonly CarbonImmutable $fromPostedAt,
        public readonly string $fromAccountName,
        public readonly array $evidenceLines,
        public readonly ?string $fromCounterpartySlug = null,
    ) {}
}
