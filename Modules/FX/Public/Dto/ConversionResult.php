<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Ledger\Public\ValueObjects\Money;
use Spatie\LaravelData\Data;

/**
 * Result of a currency conversion (or a zero-overhead passthrough).
 *
 * Every converted figure in the app carries a ConversionResult so the
 * Blade disclosure affordance (D-11/D-12, FX-04) can render the rate,
 * its source, and the as-of date on demand.
 *
 * `passthrough()` is the zero-cost path (D-03): when a figure's currency
 * already equals the user's base currency, no conversion runs and the
 * result carries no rate metadata — the Blade guard skips the disclosure
 * affordance entirely when `isPassthrough` is true.
 *
 * `$rate` is typed `?string` (never float) to guard Pitfall 1: floating-
 * point representation silently corrupts FX conversion precision. The
 * string is the DECIMAL(18,8) value retrieved from `exchange_rates.rate`.
 *
 * `$isStale` is true when the best available rate is older than the
 * freshness threshold (D-07/D-12) — the Blade affordance renders an amber
 * marker and the popover detail explains how to enable online refresh.
 */
final class ConversionResult extends Data
{
    public function __construct(
        public readonly Money $original,
        public readonly Money $converted,
        public readonly bool $isPassthrough,
        public readonly ?string $rate,
        public readonly ?string $source,
        public readonly ?CarbonImmutable $asOf,
        public readonly bool $isStale,
    ) {}

    /**
     * Zero-overhead passthrough for figures already in the base currency
     * (D-03). No conversion runs; original === converted; all rate
     * metadata is null; isStale is false.
     */
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
