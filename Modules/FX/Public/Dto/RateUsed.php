<?php

declare(strict_types=1);

namespace Modules\FX\Public\Dto;

use Carbon\CarbonImmutable;
use Modules\Core\Public\Support\Fmt;
use Modules\Core\Public\Support\Lang;
use Modules\FX\Public\Services\ExchangeRateService;
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

    // Null where a conversion has no rate to disclose: a passthrough converted
    // at none, and a pair the table could not reach at none either. A caller
    // that skips the check hands its set a leg that then answers for the
    // whole figure, which is why the check is not left to callers.
    public static function fromConversion(ConversionResult $result): ?self
    {
        if ($result->isPassthrough || $result->rate === null) {
            return null;
        }

        return new self(
            from: $result->original->currency(),
            to: $result->converted->currency(),
            rate: $result->rate,
            source: $result->source,
            asOf: $result->asOf,
            isStale: $result->isStale,
        );
    }

    // A rate read back out of a stored result. Staleness is a fact about the
    // day it is READ, so storing the boolean would let it decay under an
    // as-of date that stays true.
    public static function asReadOn(
        CarbonImmutable $readOn,
        string $from,
        string $to,
        string $rate,
        ?string $source,
        ?CarbonImmutable $asOf,
    ): self {
        return new self(
            from: $from,
            to: $to,
            rate: $rate,
            source: $source,
            asOf: $asOf,
            isStale: $asOf !== null
                && abs($asOf->startOfDay()->diffInDays($readOn->startOfDay())) > ExchangeRateService::STALE_DAYS_THRESHOLD,
        );
    }

    // The column's own eight places, not the three significant digits a rate
    // reads at beside a figure: a reader cannot reproduce EUR 3,016.97 from
    // 0.00629, and reproducing it is what the disclosure is for. Rate::parse
    // also reads the exact rational a cross-rate arrives as.
    public function rateForDisplay(): string
    {
        return Fmt::decimalString(Rate::parse($this->rate)?->exact() ?? $this->rate);
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
