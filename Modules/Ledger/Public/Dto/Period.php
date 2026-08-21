<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

final class Period extends Data
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $endExclusive,
        public readonly string $label,
    ) {}
}
