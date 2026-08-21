<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Exceptions;

use Modules\Chains\Models\ChainLink;
use RuntimeException;

// Raised ahead of the write because the schema's NULL-endpoint trigger
// would otherwise surface as a raw SQLSTATE[23000] violation.
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
