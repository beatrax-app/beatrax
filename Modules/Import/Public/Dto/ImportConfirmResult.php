<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

/**
 * Outcome of the confirm phase. The wizard's results page renders the
 * "Imported N transactions · skipped M duplicates" summary directly from
 * these counts.
 */
final class ImportConfirmResult extends Data
{
    public function __construct(
        public readonly int $importRunId,
        public readonly int $inserted,
        public readonly int $duplicates,
        public readonly int $errors,
    ) {}
}
