<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Single row payload for the `/chains/review` candidate queue. One
 * instance per `chain_links.state = 'candidate'` row that surfaces in
 * the review surface.
 *
 * The DTO carries both endpoints' display data so the Blade view can
 * render the candidate's "from" and "to" sides without re-querying.
 * `$confirmsRemaining` is the auto-promotion hint — once the user
 * has confirmed two same-signature candidates, the third confirmation
 * promotes the signature to auto-confirm via `resolver='rule'`.
 *
 * `$fromCounterpartySlug` / `$toCounterpartySlug` carry the resolved-
 * counterparty slug for each endpoint's counterparty-name click-through
 * anchor that routes to `counterparties.profile`. NULL when the
 * corresponding transaction has no resolved counterparty — the Blade
 * then renders the counterparty name as plain text instead of a link.
 */
final class ChainLinkRow extends Data
{
    public function __construct(
        public readonly int $chainLinkId,
        public readonly string $kind,
        public readonly string $state,
        public readonly float $confidence,
        public readonly int $fromTransactionId,
        public readonly string $fromCounterparty,
        public readonly Money $fromAmount,
        public readonly int $toTransactionId,
        public readonly string $toCounterparty,
        public readonly Money $toAmount,
        public readonly CarbonImmutable $fromPostedAt,
        public readonly CarbonImmutable $toPostedAt,
        public readonly int $confirmsRemaining,
        public readonly ?string $fromCounterpartySlug = null,
        public readonly ?string $toCounterpartySlug = null,
    ) {}
}
