<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Exceptions;

use RuntimeException;

// Thrown by a ParsesMigrationSource implementation when the extracted-upload
// directory does not match its expected shape (missing file, corrupt
// archive, required column/table absent) — thrown BEFORE yielding any
// MigrationBatch, so a corrupt file is rejected, never a partial import.
final class UnrecognizedMigrationFileException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('Unrecognized or corrupt migration source file: '.$reason);
    }
}
