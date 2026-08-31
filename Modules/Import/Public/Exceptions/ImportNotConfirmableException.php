<?php

declare(strict_types=1);

namespace Modules\Import\Public\Exceptions;

use Modules\Import\Internal\Enums\ConfirmRefusal;
use RuntimeException;

// Confirming would have flipped the run to confirmed while writing nothing,
// which the idempotency key then makes permanent: the window never re-fetches
// and the rows are gone. Every caller gets this, not just the wizard.
final class ImportNotConfirmableException extends RuntimeException
{
    public function __construct(
        public readonly int $importRunId,
        public readonly ConfirmRefusal $refusal,
    ) {
        parent::__construct(sprintf(
            'Import run %d cannot be confirmed: %s.',
            $importRunId,
            $refusal->sentence(),
        ));
    }
}
