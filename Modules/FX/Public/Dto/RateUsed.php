<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Support\BundledRates;
use Modules\Ledger\Public\ValueObjects\Rate;

// One leg of a converted figure, kept whole rather than reduced to its number.
// A rate fetched this morning and a bundled snapshot ninety-nine days old
// convert identically and mean entirely different things to the reader, so the
// source and the day it was published travel with the rate or they are lost.
final readonly class RateUsed
{
    public function __construct(
        public string $from,
        public string $to,
        // Never float: the DECIMAL(18,8) exchange_rates.rate, kept a string so
        // the figure the reader is shown is the one the conversion used.
        public string $rate,
        public ?string $source,
        public ?CarbonImmutable $asOf,
        public bool $isStale,
    ) {}

    // Three decimals, or as many as it takes to keep three significant digits
    // of a smaller rate: euro-per-yen is 0.00628536, and four fixed places
    // wrote 0.0063. Through BigNumber because a cross-rate arrives as the
    // exact rational brick/money derived it from — "25/27", not a decimal.
    public function rateForDisplay(): string
    {
        try {
            $exact = Rate::fromNumber(BigNumber::of($this->rate));
        } catch (MathException) {
            $exact = null;
        }

        return Fmt::decimalString($exact?->forDisplay() ?? $this->rate);
    }

    // The institution, not the column value. Several locales abbreviate the
    // central bank in their own script; Frankfurter is a service name and
    // stays itself in all twenty-six.
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'ecb' => Lang::get('core::fx.source_ecb'),
            'frankfurter' => 'Frankfurter',
            BundledRates::SOURCE => Lang::get('core::fx.source_bundled'),
            'transaction' => Lang::get('core::fx.source_transaction'),
            null, '' => Lang::get('core::fx.source_fallback'),
            default => ucfirst($this->source),
        };
    }

    public function ageInDaysAt(CarbonImmutable $when): ?int
    {
        return $this->asOf === null
            ? null
            : (int) $this->asOf->startOfDay()->diffInDays($when->startOfDay(), absolute: true);
    }
}
