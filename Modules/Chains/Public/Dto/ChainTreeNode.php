<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * One leg of the chain waterfall. The root node has `$chainLinkId` of
 * null and `$kind` equal to `'root'`. Funder legs carry their
 * `chain_links.id`, the relationship kind (`paypal_funding` /
 * `ics_bulk_settle`), and a `$confidenceTier` that maps the raw
 * `(state, resolver, confidence)` tuple to the UI's three-tier chip
 * (`Deterministic` / `Confirmed` / `Candidate`).
 *
 * `$children` carries nested fan-out — populated when an ICS bulk-
 * settle node covers multiple underlying ICS expenses.
 */
final class ChainTreeNode extends Data
{
    /**
     * @param  array<ChainTreeNode>  $children
     */
    public function __construct(
        public readonly int $transactionId,
        public readonly ?int $chainLinkId,
        public readonly string $counterpartyName,
        public readonly Money $amount,
        public readonly CarbonImmutable $bookedAt,
        public readonly string $accountName,
        public readonly string $kind,
        public readonly string $confidenceTier,
        public readonly array $children = [],
        public readonly ?string $counterpartySlug = null,
    ) {}
}
