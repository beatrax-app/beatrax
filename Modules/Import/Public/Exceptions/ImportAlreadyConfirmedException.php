<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

// Thrown by DiscardImport when asked to discard an already-'confirmed'
// run — that would leave the audit row marked 'discarded' while the
// ledger rows it created remain (orphaned audit history). Callers must
// remove the underlying transactions separately before retiring the row.
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
