<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by a `ParsesMigrationSource` implementation when the supplied
 * extracted-upload directory does not match that parser's expected shape —
 * a missing required file, an unreadable/corrupt archive, or a required
 * column/table the parser depends on is absent.
 *
 * Part of the Req 1 "corrupt file rejected, not partial import" contract:
 * a parser must throw this BEFORE yielding any `MigrationBatch`, never
 * return a partially-populated one. `StartMigrationRun` (Plan 05) catches
 * this to flip the migration run to a rejected state with zero staging rows
 * written, rather than leaving a half-imported run behind.
 */
final class UnrecognizedMigrationFileException extends RuntimeException
{
    public function __construct(string $reason)
    {
        parent::__construct('Unrecognized or corrupt migration source file: '.$reason);
    }
}
