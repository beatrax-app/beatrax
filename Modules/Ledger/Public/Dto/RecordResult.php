<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

final class RecordResult extends Data
{
    public function __construct(
        public readonly int $inserted,
        public readonly int $duplicates,
    ) {}
}
