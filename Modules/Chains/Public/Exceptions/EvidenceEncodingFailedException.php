<?php

declare(strict_types=1);

namespace Modules\Chains\Public\Exceptions;

use RuntimeException;

// chain_links.evidence is written as JSON, so an encode failure means the
// payload holds something json_encode cannot represent: a malformed byte
// sequence or a recursive structure. Two call sites raised this as a bare
// RuntimeException; the type now says which column refused the write.
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
