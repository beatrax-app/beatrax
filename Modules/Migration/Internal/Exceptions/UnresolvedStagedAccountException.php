<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// Thrown by PromoteStagingToDomain when a staged transaction names an
// account external id that never resolved to a promoted Account.
// Accounts are promoted before transactions, so this is a broken
// staging invariant, never a user-correctable data problem.
/**
 * @link ../../../../.docs/features/migration/architecture.md
 */
final class UnresolvedStagedAccountException extends RuntimeException
{
    public function __construct(string $accountExternalId)
    {
        parent::__construct("Migration promote: no resolved account for staged transaction account '{$accountExternalId}'.");
    }
}
