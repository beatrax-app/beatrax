<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Stringable;

// fx_rate_used is decimal(18,8), far beyond the two decimals Money is built
// on, so a rate gets its own type. Held as a decimal string end to end:
// brick/math silently truncates a float argument to int, which is how a
// stored 0.92917629 once rendered as 0.
final readonly class Rate implements Stringable
{
    // The scale fx_rate_used is stored at. A derived pair is a ratio with no
    // terminating decimal — USD->EUR through a 1.08 base rate is 25/27 — so
    // every route into this type names the scale it lands on.
    public const int SCALE = 8;

    // What a rate reads at on a page: three decimals, or as many as it takes
    // to keep three significant digits of a rate smaller than that.
    public const int DISPLAY_SCALE = 3;

    public const int DISPLAY_DIGITS = 3;

    private function __construct(private BigDecimal $inner) {}

    // Null, never a guess, when the value is not a decimal — a rate column
    // read back as something unexpected must not abort the render.
    public static function of(string $decimal): ?self
    {
        try {
            return new self(BigDecimal::of($decimal));
        } catch (MathException) {
            return null;
        }
    }

    // A rate the library derived rather than stored, which arrives as an
    // exact rational: 25/27, not 0.925…. Rounding it here is the only place
    // the loss happens, and it happens at the column's own scale.
    public static function fromNumber(BigNumber $number, int $scale = self::SCALE): ?self
    {
        try {
            return new self($number->toScale(self::nonNegative($scale), RoundingMode::HalfUp));
        } catch (MathException) {
            return null;
        }
    }

    // The effective rate between two legs of one transaction, e.g. a settled
    // EUR amount over the native JPY it paid. Divided in major units, because
    // the legs need not share a minor-unit scale: 5800 euro cents over 10000
    // yen is 0.58, a hundred times the rate the reader is owed.
    public static function between(Money $numerator, Money $denominator, int $scale = self::SCALE): ?self
    {
        if ($denominator->toMinor() === 0) {
            return null;
        }

        try {
            return new self(
                BigDecimal::of($numerator->toMajorString())
                    ->dividedBy(BigDecimal::of($denominator->toMajorString()), self::nonNegative($scale), RoundingMode::HalfUp),
            );
        } catch (MathException) {
            return null;
        }
    }

    public function toScale(int $scale): self
    {
        return new self($this->inner->toScale(self::nonNegative($scale), RoundingMode::HalfUp));
    }

    // Three decimals render a euro-per-dollar rate exactly and a euro-per-yen
    // one as 0.006, three percent off the 0.0058 the card charged. The scale
    // grows until DISPLAY_DIGITS significant ones survive it, and stops where
    // the column's own scale does.
    public function forDisplay(): string
    {
        [$whole, $fraction] = array_pad(
            explode('.', (string) $this->inner->abs()->toScale(self::SCALE, RoundingMode::HalfUp)),
            2,
            '',
        );

        if ($whole !== '0') {
            return (string) $this->toScale(self::DISPLAY_SCALE);
        }

        $leadingZeros = strlen($fraction) - strlen(ltrim($fraction, '0'));

        return (string) $this->toScale(min(
            self::SCALE,
            max(self::DISPLAY_SCALE, $leadingZeros + self::DISPLAY_DIGITS),
        ));
    }

    // brick/math reads a negative scale as "round to tens, hundreds", which is
    // never what a caller asking for fewer decimals meant.
    /** @return int<0, max> */
    private static function nonNegative(int $scale): int
    {
        return max(0, $scale);
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
