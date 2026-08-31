<?php

declare(strict_types=1);

namespace Modules\Import\Internal\Dto;

use Modules\Core\Models\User;
use Modules\Ingestion\Public\Contracts\AccountResolver;

// What every row of one preview is read against: the format it was declared
// as, the account book its IBANs resolve through, whose ledger it lands in and
// the run it is filed under. They are one object because each stage needs a
// different three of the four, and no stage may be handed a mix from two runs.
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
    ) {}
}
