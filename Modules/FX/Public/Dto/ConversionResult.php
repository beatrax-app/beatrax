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
        // Never float — the DECIMAL(18,8) value read from
        // exchange_rates.rate, kept as a string to avoid floating-point
        // precision loss during conversion.
        public readonly ?string $rate,
        public readonly ?string $source,
        public readonly ?CarbonImmutable $asOf,
        public readonly bool $isStale,
    ) {}

    // Zero-overhead passthrough for figures already in the base
    // currency: no conversion runs, original === converted, all rate
    // metadata is null, isStale is false.
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
