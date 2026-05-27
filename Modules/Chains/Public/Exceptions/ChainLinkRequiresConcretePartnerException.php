<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Exceptions;

use Modules\Chains\Models\ChainLink;
use RuntimeException;

/**
 * Thrown when a public chain-link action (confirm or reject) is invoked
 * against a hint-shaped row whose `to_transaction_id IS NULL`.
 *
 * The schema's `chain_links_to_transaction_id_check_*` triggers permit
 * NULL endpoints ONLY while a row is in `state='candidate'` AND is one
 * of the documented hint variants (an exceeded-tolerance
 * `ics_bulk_settle` candidate, a `funded_by_card_hint`, or a
 * `refund_of_hint`). Promoting such a row to `confirmed` or `rejected`
 * without first attaching a concrete partner trips the trigger and
 * produces an SQLSTATE[23000] integrity-constraint violation that
 * crashes the request with a 500.
 *
 * The actions catch the NULL-endpoint shape BEFORE the save and throw
 * this typed exception instead, so the Livewire (or any other) caller
 * can render a user-readable message — "open the candidate to choose a
 * partner first" — rather than the bare SQL error.
 */
final class ChainLinkRequiresConcretePartnerException extends RuntimeException
{
    public function __construct(
        public readonly int $chainLinkId,
        public readonly string $kind,
        public readonly string $state,
    ) {
        parent::__construct(sprintf(
            'Chain link %d (kind=%s, state=%s) is a hint candidate without a concrete partner — '
                .'attach the matching transaction before confirming or rejecting.',
            $chainLinkId,
            $kind,
            $state,
        ));
    }

    public static function from(ChainLink $link): self
    {
        return new self(
            chainLinkId: $link->id,
            kind: $link->kind,
            state: $link->state,
        );
    }
}
