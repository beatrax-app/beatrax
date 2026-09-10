<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Dto;

use Modules\Core\Models\User;
use Modules\Ingestion\Public\Contracts\AccountResolver;
use Modules\Ledger\Public\Services\OccurrenceOrdinals;

// What every row of one preview is read against: the format, the account book
// its IBANs resolve through, whose ledger it lands in, the run it is filed
// under, and how many rows of each shape it has read. They are one object
// because no stage may be handed a mix from two runs.
/**
 * @link ../../../../.docs/architecture/ingestion-pipeline.md
 */
final readonly class PreviewRun
{
    public function __construct(
        public string $sourceFormat,
        public AccountResolver $accounts,
        public User $user,
        public int $importRunId,
        // Per run and never on the pipeline, which is a singleton: a counter
        // outliving the file it counted would number the next file's rows from
        // where this one stopped.
        public OccurrenceOrdinals $ordinals,
    ) {}
}
