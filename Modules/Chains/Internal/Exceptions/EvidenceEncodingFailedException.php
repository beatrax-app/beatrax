<?php

declare(strict_types=1);

namespace Modules\Chains\Internal\Exceptions;

use RuntimeException;

final class EvidenceEncodingFailedException extends RuntimeException
{
    public function __construct(public readonly string $context)
    {
        parent::__construct(sprintf(
            'Failed to json_encode chain_links.evidence (%s)',
            $context,
        ));
    }
}
