<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// Thrown before any MigrationBatch is yielded, so a corrupt source is rejected
// whole rather than half-imported.
final class UnrecognizedMigrationFileException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('Unrecognized or corrupt migration source file: '.$reason);
    }
}
