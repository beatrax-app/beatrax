<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Exceptions;

use RuntimeException;

// The generalized pattern matches as a whole token against every description
// the reader owns, so two characters rename an entire ledger. The settings page
// refused one; the rename popover reached the same table without asking.
final class MerchantAliasPatternTooShortException extends RuntimeException
{
    public function __construct(public readonly int $minimumLength)
    {
        parent::__construct(sprintf(
            'A merchant alias pattern must be at least %d characters.',
            $minimumLength,
        ));
    }
}
