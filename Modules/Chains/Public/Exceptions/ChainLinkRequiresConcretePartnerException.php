<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Exceptions;

use Modules\Chains\Models\ChainLink;
use RuntimeException;

// Thrown when confirm/reject is invoked against a hint-shaped row whose
// to_transaction_id IS NULL — the schema trigger would otherwise crash
// the request with a raw SQLSTATE[23000] integrity-constraint violation
// instead of a readable "attach a partner first" message.
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
