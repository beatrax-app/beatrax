<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

// Raised only where the run's status IS 'discarded', so the message says that
// and not the two neighbouring causes the guard has already excluded: an
// unparsed run and a parse that gave up before staging both keep their rows.
final class MigrationRunNotParsedException extends RuntimeException
{
    public function __construct(int $migrationRunId)
    {
        parent::__construct("Migration run {$migrationRunId} was discarded, so its staged rows were truncated and there is nothing left to summarise.");
    }
}
