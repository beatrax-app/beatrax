<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\Exceptions;

use RuntimeException;

// Another rebuild holds the maintenance lock for this user. A rebuild
// rewrites the whole op-log from its entries, so two running at once would
// interleave writes over the same rows — refusing the second is the point of
// the lock, not an error in the caller.
final class RebuildInProgressException extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self("Op-log rebuild already in progress for user {$userId} (maintenance lock held).");
    }
}
