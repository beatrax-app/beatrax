<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

// One confirm attempt in isolation. A re-confirm reports 0 inserted and
// counts the original inserts as duplicates, so these must never be summed
// across attempts for the same run.
final class ImportConfirmResult extends Data
{
    public function __construct(
        public readonly int $importRunId,
        public readonly int $inserted,
        public readonly int $duplicates,
        public readonly int $enriched,
        public readonly int $errors,
    ) {}
}
