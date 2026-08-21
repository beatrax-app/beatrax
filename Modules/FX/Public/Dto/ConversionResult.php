<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class ConversionResult extends Data
{
    public function __construct(
        public readonly Money $original,
        public readonly Money $converted,
        public readonly bool $isPassthrough,
        // Never float: the DECIMAL(18,8) exchange_rates.rate, kept a string so
        // conversion loses no precision.
        public readonly ?string $rate,
        public readonly ?string $source,
        public readonly ?CarbonImmutable $asOf,
        public readonly bool $isStale,
    ) {}

    // A passthrough for figures already in the base currency: no conversion
    // runs and every rate field stays null.
    public static function passthrough(Money $money): self
    {
        return new self(
            original: $money,
            converted: $money,
            isPassthrough: true,
            rate: null,
            source: null,
            asOf: null,
            isStale: false,
        );
    }
}
