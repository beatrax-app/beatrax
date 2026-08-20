<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Stringable;

// fx_rate_used is decimal(18,8), far beyond the two decimals Money is built
// on, so a rate gets its own type. Held as a decimal string end to end:
// brick/math silently truncates a float argument to int, which is how a
// stored 0.92917629 once rendered as 0.
final class Rate implements Stringable
{
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

    // The effective rate between two legs of one transaction, e.g. a settled
    // EUR amount over the native USD amount it paid. Both legs are integer
    // minor units on the same 1/100 scale, so their ratio is the major-unit
    // ratio unchanged. Null when the denominator is zero.
    public static function between(Money $numerator, Money $denominator, int $scale): ?self
    {
        if ($denominator->toMinor() === 0) {
            return null;
        }

        try {
            return new self(
                BigDecimal::of((string) $numerator->toMinor())
                    ->dividedBy(BigDecimal::of((string) $denominator->toMinor()), $scale, RoundingMode::HalfUp),
            );
        } catch (MathException) {
            return null;
        }
    }

    public function toScale(int $scale): self
    {
        return new self($this->inner->toScale($scale, RoundingMode::HalfUp));
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }
}
