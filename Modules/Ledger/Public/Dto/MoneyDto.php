<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\Dto;

use Spatie\LaravelData\Data;

// Use the Money value object for arithmetic; this DTO is for crossing
// the wire/DB boundary as plain data.
final class MoneyDto extends Data
{
    public function __construct(
        public readonly int $minorUnits,
        public readonly string $currency,
    ) {}
}
