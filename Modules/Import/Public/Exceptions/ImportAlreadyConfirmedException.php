<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use RuntimeException;

/**
 * Thrown by DiscardImport when the caller attempts to discard an import
 * run that has already reached status 'confirmed'. Discarding after
 * confirmation would leave the audit row marked 'discarded' while the
 * ledger rows it created remain — orphaned audit history. The action
 * refuses; callers must remove the underlying transactions separately
 * (a future feature) before the audit row can be retired.
 */
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
