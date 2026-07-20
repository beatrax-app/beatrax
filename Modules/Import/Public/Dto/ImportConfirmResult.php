<?php

declare(strict_types=1);

namespace Modules\Import\Public\Dto;

use Spatie\LaravelData\Data;

// Each result describes ONE confirm attempt in isolation: on a
// re-confirm of an already-`confirmed` run, `inserted` is 0 and the
// original inserts are reported as `duplicates`, so `inserted +
// duplicates` must NOT be summed across attempts for the same run.
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
