<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

final class MigrationRunNotParsedException extends RuntimeException
{
    public function __construct(int $migrationRunId)
    {
        parent::__construct("Migration run {$migrationRunId} has no staged rows — it has not been parsed yet, or parsing failed before staging completed.");
    }
}
