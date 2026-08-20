<?php

declare(strict_types=1);

namespace Modules\Migration\Public\Exceptions;

use RuntimeException;

final class MigrationAlreadyDiscardedException extends RuntimeException
{
    public function __construct(public readonly int $migrationRunId)
    {
        parent::__construct(sprintf(
            'Migration run %d is already discarded; confirming would flip its status back to confirmed with no real promoted rows.',
            $migrationRunId,
        ));
    }
}
