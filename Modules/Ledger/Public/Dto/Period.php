<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

// A half-open period window [start, endExclusive); label is the
// human-readable string the dashboard displays in the period switcher.
final class Period extends Data
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $endExclusive,
        public readonly string $label,
    ) {}
}
