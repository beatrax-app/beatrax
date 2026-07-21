<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

// One instance per chain_links row whose to_transaction_id IS NULL,
// carrying the "from" side display data + a human-readable evidence
// summary so the blade view never re-queries the source row.
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
