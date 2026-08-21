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
final class Rate implements Stringable
{
    // The scale fx_rate_used is stored at. A derived pair is a ratio with no
    // terminating decimal — USD->EUR through a 1.08 base rate is 25/27 — so
    // every route into this type names the scale it lands on.
    public const int SCALE = 8;

    private function __construct(private readonly BigDecimal $inner) {}

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
    // EUR amount over the native USD it paid. Both legs are integer minor
    // units on one 1/100 scale, so their ratio is the major-unit ratio.
    public static function between(Money $numerator, Money $denominator, int $scale = self::SCALE): ?self
    {
        if ($denominator->toMinor() === 0) {
            return null;
        }

        try {
            return new self(
                BigDecimal::of((string) $numerator->toMinor())
                    ->dividedBy(BigDecimal::of((string) $denominator->toMinor()), self::nonNegative($scale), RoundingMode::HalfUp),
            );
        } catch (MathException) {
            return null;
        }
    }

    public function toScale(int $scale): self
    {
        return new self($this->inner->toScale(self::nonNegative($scale), RoundingMode::HalfUp));
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
