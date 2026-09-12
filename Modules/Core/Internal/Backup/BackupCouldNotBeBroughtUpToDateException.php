<?php

declare(strict_types=1);

namespace Modules\Core\Internal\Backup;

use RuntimeException;
use Throwable;

// A forward run that stopped part way. It ran on a staged copy, so what it left
// half-applied is a file the refusal throws away — this store has no schema
// transaction, and the same failure on the live database would be permanent.
final class BackupCouldNotBeBroughtUpToDateException extends RuntimeException
{
    public function __construct(string $message, public readonly int $pending, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
