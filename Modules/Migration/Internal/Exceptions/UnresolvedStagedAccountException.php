<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// Accounts are promoted before transactions, so an unresolved account id is a
// broken staging invariant, never a user-correctable data problem.
final class UnresolvedStagedAccountException extends RuntimeException
{
    public function __construct(string $accountExternalId)
    {
        parent::__construct("Migration promote: no resolved account for staged transaction account '{$accountExternalId}'.");
    }
}
