<?php

declare(strict_types=1);

namespace Modules\Forecasting\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\FX\Public\Dto\ConversionDisclosure;
use Modules\FX\Public\Dto\RateSet;
use Modules\FX\Public\Dto\RateUsed;
use Spatie\LaravelData\Data;

final class AccountBalanceLine extends Data
{
    public function __construct(
        public readonly int $accountId,
        public readonly string $name,
        public readonly string $kind,
        public readonly int $balanceMinor,
        public readonly string $currency,
        public readonly bool $isLiability,
        public readonly ?int $baseEquivalentMinor = null,
        public readonly ?string $fxRate = null,
        public readonly ?string $fxSource = null,
        public readonly ?CarbonImmutable $fxAsOf = null,
        public readonly bool $fxIsStale = false,
    ) {}

    public function isConverted(): bool
    {
        return $this->baseEquivalentMinor !== null;
    }

    // The base currency arrives from the caller because a line records the
    // currency it was converted FROM and the rate it converted at, never the
    // one it landed in — every line on a card lands in the same one.
    public function disclosure(string $baseCurrency): ?ConversionDisclosure
    {
        if ($this->fxRate === null) {
            return null;
        }

        return ConversionDisclosure::of(RateSet::of($baseCurrency, [
            $this->currency => new RateUsed(
                from: $this->currency,
                to: $baseCurrency,
                rate: $this->fxRate,
                source: $this->fxSource,
                asOf: $this->fxAsOf,
                isStale: $this->fxIsStale,
            ),
        ]));
    }

    public function hasNoRate(string $baseCurrency): bool
    {
        return $this->currency !== $baseCurrency && $this->baseEquivalentMinor === null;
    }
}
