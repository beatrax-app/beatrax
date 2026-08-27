<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Enums\ConversionOutcome;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

final class ConversionResult extends Data
{
    // Derived rather than passed in, so the flag and the outcome cannot
    // disagree: it was a constructor argument, and every failed conversion
    // was handed `true` for it.
    public readonly bool $isPassthrough;

    public function __construct(
        public readonly Money $original,
        public readonly Money $converted,
        public readonly ConversionOutcome $outcome,
        // Never float: the DECIMAL(18,8) exchange_rates.rate, kept a string so
        // conversion loses no precision.
        public readonly ?string $rate,
        public readonly ?string $source,
        public readonly ?CarbonImmutable $asOf,
        public readonly bool $isStale,
    ) {
        $this->isPassthrough = $outcome === ConversionOutcome::Passthrough;
    }

    // A figure already in the base currency: no conversion runs and every rate
    // field stays null.
    public static function passthrough(Money $money): self
    {
        return self::unconverted($money, ConversionOutcome::Passthrough);
    }

    // No rate could be found for the pair, so the amount is handed back in the
    // currency it arrived in. The caller must leave it out of a base-currency
    // total rather than add it at one to one.
    public static function noRate(Money $money): self
    {
        return self::unconverted($money, ConversionOutcome::NoRate);
    }

    private static function unconverted(Money $money, ConversionOutcome $outcome): self
    {
        return new self(
            original: $money,
            converted: $money,
            outcome: $outcome,
            rate: null,
            source: null,
            asOf: null,
            isStale: false,
        );
    }
}
