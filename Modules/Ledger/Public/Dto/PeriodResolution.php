<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// What a stored view anchor resolved to. isoDate is the anchor the caller
// should keep — null once a value that is no longer a date has been dropped —
// so clearing it is a value the caller can see rather than a hidden side
// effect on the argument it passed in.
final class PeriodResolution extends Data
{
    public function __construct(
        public readonly Period $period,
        public readonly ?string $isoDate,
    ) {}
}
