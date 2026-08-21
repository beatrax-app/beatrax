<?php

declare(strict_types=1);

namespace Modules\Migration\Internal\Exceptions;

use RuntimeException;

final class MigrationAlreadyConfirmedException extends RuntimeException
{
    public function __construct(public readonly int $migrationRunId)
    {
        parent::__construct(sprintf(
            'Migration run %d is already confirmed; discarding would orphan its promoted domain rows.',
            $migrationRunId,
        ));
    }
}
