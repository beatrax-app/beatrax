<?php

declare(strict_types=1);

namespace Modules\Ingestion\Public\Exceptions;

use Modules\Core\Public\Support\MessageNamesNoUserData;
use RuntimeException;

// A child row promoted to a parent because the row its Reference Txn ID names
// is not in this file — a statement split at a month boundary is how that
// happens. The message names PayPal's own event vocabulary and nothing of the
// reader's, so it is safe to show.
final class OrphanedPaypalChildRowException extends RuntimeException implements MessageNamesNoUserData
{
    public function __construct(public readonly string $eventType)
    {
        parent::__construct(
            "This '{$eventType}' row belongs to a PayPal transaction that is not in this file. Import the statement that contains it as well — the two statements are read together."
        );
    }
}
