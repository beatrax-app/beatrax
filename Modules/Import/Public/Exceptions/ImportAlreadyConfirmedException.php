<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

// Discarding a confirmed run would mark the audit row 'discarded' while the
// ledger rows it created remain. Remove those separately first.
final class ImportAlreadyConfirmedException extends RuntimeException
{
    public function __construct(public readonly int $importRunId)
    {
        parent::__construct(sprintf(
            'Import run %d is already confirmed; discarding would orphan its ledger rows.',
            $importRunId,
        ));
    }
}
