<?php

declare(strict_types=1);

namespace Modules\Ledger\Public\ValueObjects;

use Brick\Money\Money as BrickMoney;
use Stringable;

/**
 * Immutable money value with a single integer-minor factory entry point.
 *
 * Wraps `brick/money` so domain code never imports brick directly. The only
 * way to construct a Money is `ofMinor(int, string)` — there is no `ofFloat`
 * or `fromString` on purpose: floats and locale-aware string parsing are the
 * exact failure modes the integer-only money handling rules exist to
 * prevent.
 */
final class Money implements Stringable
{
    private function __construct(private readonly BrickMoney $inner) {}

    /**
     * Constructs Money from a signed integer minor-unit amount and an ISO 4217
     * currency code. Negative values represent debits (money out).
     */
    public static function ofMinor(int $minor, string $currencyCode): self
    {
        return new self(BrickMoney::ofMinor($minor, $currencyCode));
    }

    /**
     * Returns a new Money representing this + other. Throws when the
     * currencies differ (brick/money MoneyMismatchException).
     */
    public function plus(self $other): self
    {
        return new self($this->inner->plus($other->inner));
    }

    /**
     * Returns a new Money representing this - other. Throws when the
     * currencies differ.
     */
    public function minus(self $other): self
    {
        return new self($this->inner->minus($other->inner));
    }

    public function toMinor(): int
    {
        return $this->inner->getMinorAmount()->toInt();
    }

    public function currency(): string
    {
        return $this->inner->getCurrency()->getCurrencyCode();
    }

    public function isNegative(): bool
    {
        return $this->inner->isNegative();
    }

    public function format(string $locale = 'nl_NL'): string
    {
        return $this->inner->formatTo($locale);
    }

    public function __toString(): string
    {
        return $this->inner->__toString();
    }
}
